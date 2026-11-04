<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Pausing, resuming and editing behind a report link that has already been sent
|--------------------------------------------------------------------------
|
| A link in a client's inbox cannot be recalled or re-read from the database (only its hash is stored), so everything an
| agency wants to do to it afterwards has to leave the address alone: edit the report behind it, switch it off for a
| while, switch it back on. Pausing is not revoking — revoking is final and needs a reason; pausing keeps the token.
|
*/

/** @return array{scenario: WorkspaceScenario, report: string} */
function pauseableShareScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $report = test()->actingAs($scenario->owner)
        ->postJson('/api/reports', [
            'name' => 'Monthly performance',
            'client_id' => (string) $client->getKey(),
            'audience' => 'client_safe',
            'window' => 'last_30_days',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
        ])
        ->assertCreated()
        ->json('data.id');

    return ['scenario' => $scenario, 'report' => (string) $report];
}

/** @param array{scenario: WorkspaceScenario, report: string} $setup */
function pauseableShareLink(array $setup): string
{
    return (string) test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7])
        ->assertCreated()
        ->json('meta.token');
}

function pauseableShareId(string $reportId): string
{
    return (string) DB::table('report_shares')->where('report_id', $reportId)->value('id');
}

it('keeps the same link working after the report behind it is edited', function (): void {
    $setup = pauseableShareScenario();
    $token = pauseableShareLink($setup);
    $hash = DB::table('report_shares')->where('report_id', $setup['report'])->value('token_hash');

    test()->actingAs($setup['scenario']->owner)
        ->putJson("/api/reports/{$setup['report']}", [
            'name' => 'Monthly performance — revised',
            'audience' => 'client_safe',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend']]],
        ])
        ->assertOk();

    // The token row is untouched, and the same address now shows the edited report.
    expect(DB::table('report_shares')->where('report_id', $setup['report'])->value('token_hash'))->toBe($hash);
    $data = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');
    expect($data['state'])->toBe('active')->and($data['mode'])->toBe('live')
        ->and($data['report']['name'])->toBe('Monthly performance — revised');
});

it('pauses a link so a stranger gets a sentence and no figures, then resumes it at the same address', function (): void {
    $setup = pauseableShareScenario();
    $token = pauseableShareLink($setup);
    $shareId = pauseableShareId($setup['report']);

    test()->actingAs($setup['scenario']->owner)->postJson("/api/report-shares/{$shareId}/pause")->assertNoContent();

    $paused = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');
    expect($paused['state'])->toBe('paused')
        ->and($paused['message'])->toContain('paused')
        ->and($paused['report'])->toBeNull();

    // The owner's list says so, and the token is the same row.
    $listed = test()->actingAs($setup['scenario']->owner)->getJson("/api/reports/{$setup['report']}/shares")->assertOk()->json('data.0');
    expect($listed['state'])->toBe('paused')->and($listed['paused_at'])->not->toBeNull();

    test()->actingAs($setup['scenario']->owner)->postJson("/api/report-shares/{$shareId}/resume")->assertNoContent();

    $resumed = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');
    expect($resumed['state'])->toBe('active')->and($resumed['report']['name'])->toBe('Monthly performance');
    expect(DB::table('report_shares')->where('id', $shareId)->value('paused_at'))->toBeNull();
});

it('does not pause twice, resume what is not paused, or move the moment it was paused', function (): void {
    $setup = pauseableShareScenario();
    pauseableShareLink($setup);
    $shareId = pauseableShareId($setup['report']);
    $owner = test()->actingAs($setup['scenario']->owner);

    $owner->postJson("/api/report-shares/{$shareId}/resume")->assertNotFound();
    $owner->postJson("/api/report-shares/{$shareId}/pause")->assertNoContent();
    $first = DB::table('report_shares')->where('id', $shareId)->value('paused_at');
    $owner->postJson("/api/report-shares/{$shareId}/pause")->assertNotFound();
    expect(DB::table('report_shares')->where('id', $shareId)->value('paused_at'))->toBe($first);
});

it('never revives a revoked link and never pauses a dead one', function (): void {
    $setup = pauseableShareScenario();
    $token = pauseableShareLink($setup);
    $shareId = pauseableShareId($setup['report']);
    $owner = test()->actingAs($setup['scenario']->owner);

    $owner->postJson("/api/report-shares/{$shareId}/pause")->assertNoContent();
    $owner->deleteJson("/api/report-shares/{$shareId}", ['reason' => 'Wrong recipient.'])->assertNoContent();
    $owner->postJson("/api/report-shares/{$shareId}/resume")->assertNotFound();

    expect(test()->getJson("/api/shared-reports/{$token}")->json('data.state'))->toBe('revoked');
});

it('does not resume an expired link, and does not extend its expiry', function (): void {
    $setup = pauseableShareScenario();
    $token = pauseableShareLink($setup);
    $shareId = pauseableShareId($setup['report']);
    $owner = test()->actingAs($setup['scenario']->owner);

    $owner->postJson("/api/report-shares/{$shareId}/pause")->assertNoContent();
    $expiry = now()->subHour();
    DB::table('report_shares')->where('id', $shareId)->update(['expires_at' => $expiry]);

    $owner->postJson("/api/report-shares/{$shareId}/resume")->assertNotFound();
    expect(test()->getJson("/api/shared-reports/{$token}")->json('data.state'))->toBe('expired');
});

it('records who paused and resumed a link, and refuses a reader without the share permission', function (): void {
    $setup = pauseableShareScenario();
    pauseableShareLink($setup);
    $shareId = pauseableShareId($setup['report']);

    test()->actingAs($setup['scenario']->owner)->postJson("/api/report-shares/{$shareId}/pause")->assertNoContent();
    test()->actingAs($setup['scenario']->owner)->postJson("/api/report-shares/{$shareId}/resume")->assertNoContent();

    expect(DB::table('audit_logs')->where('action', 'report.share_paused')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'report.share_resumed')->count())->toBe(1);
    // The audit trail names the link by its hint, never the token.
    expect((string) DB::table('audit_logs')->where('action', 'report.share_paused')->value('summary'))->toContain('Paused a report link');

    $outsider = WorkspaceScenario::create();
    test()->actingAs($outsider->owner)->postJson("/api/report-shares/{$shareId}/pause")->assertNotFound();
    expect(DB::table('report_shares')->where('id', $shareId)->value('paused_at'))->toBeNull();
});
