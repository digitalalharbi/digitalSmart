<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Reading a report through a shared link
|--------------------------------------------------------------------------
|
| The only unauthenticated read in this product, so this file is mostly about what a stranger holding a URL can and
| cannot do.
|
| The token is the whole of the authorisation and it names exactly one report — there is no other parameter, so there
| is nothing to change to reach a different document. A revoked or expired link gets a sentence rather than a 404,
| because a 404 on a link a client was sent reads as a broken site and they ask for it again instead of learning that
| it lapsed. And a token nobody issued gets the same answer as a revoked one, because telling a visitor the
| difference tells anybody guessing tokens when they have guessed one.
|
*/

/** @return array{scenario: WorkspaceScenario, report: string} */
function sharedScenario(): array
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
            'internal_note' => 'Do not mention the churn risk on the call.',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
        ])
        ->assertCreated()
        ->json('data.id');

    return ['scenario' => $scenario, 'report' => (string) $report];
}

/** @param array{scenario: WorkspaceScenario, report: string} $setup */
function shareLink(array $setup, int $days = 7, ?string $snapshotId = null): string
{
    return (string) test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", [
            'days' => $days,
            ...($snapshotId === null ? [] : ['snapshot_id' => $snapshotId]),
        ])
        ->assertCreated()
        ->json('meta.token');
}

it('lets a stranger with the link read the report, with no account', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    $data = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');

    expect($data['state'])->toBe('active');
    expect($data['report']['name'])->toBe('Monthly performance');
    expect($data['mode'])->toBe('live');
});

it('never includes the agency\'s own commentary', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    $response = test()->getJson("/api/shared-reports/{$token}")->assertOk();

    /*
     * The audience is passed explicitly on this path, so no future configuration can make it serve an internal
     * render — and `ShareReport` already refuses to share an internal report at all. Two locks on the same door.
     */
    expect($response->json('data.report.internal_note'))->toBeNull();
    expect($response->content())->not->toContain('churn risk');
});

it('refuses a revoked link with a sentence rather than a 404', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    $shareId = DB::table('report_shares')->where('report_id', $setup['report'])->value('id');

    test()->actingAs($setup['scenario']->owner)
        ->deleteJson("/api/report-shares/{$shareId}", ['reason' => 'Sent to the wrong address.'])
        ->assertNoContent();

    $data = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');

    // A 404 reads as a broken site; they ask for the link again instead of learning it was turned off.
    expect($data['state'])->toBe('revoked');
    expect($data['message'])->toContain('turned off');
    expect($data['report'])->toBeNull();
});

it('refuses an expired link, and says it expired', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    DB::table('report_shares')
        ->where('report_id', $setup['report'])
        ->update(['expires_at' => now()->subDay()]);

    $data = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');

    /*
     * Derived rather than stored. A stored `expired` would need something to write it, and whatever that was would
     * be the thing that failed to run — leaving a link expired in principle and working in practice.
     */
    expect($data['state'])->toBe('expired');
    expect($data['message'])->toContain('expired');
});

it('answers a token nobody issued exactly as it answers a revoked one', function (): void {
    sharedScenario();

    $invented = str_repeat('ab', 48);

    $data = test()->getJson("/api/shared-reports/{$invented}")->assertOk()->json('data');

    /*
     * The same answer for both. Telling a visitor the difference between "never existed" and "turned off" tells
     * anybody guessing tokens when they have guessed one.
     */
    expect($data['state'])->toBe('revoked');
    expect($data['report'])->toBeNull();
});

it('does not route a token of the wrong shape at all', function (): void {
    sharedScenario();

    // The route constrains the token's shape, so a probe with a short or non-hex value never reaches the handler.
    test()->getJson('/api/shared-reports/short')->assertNotFound();
    test()->getJson('/api/shared-reports/'.str_repeat('z', 96))->assertNotFound();
});

it('serves a snapshot frozen, and says so', function (): void {
    $setup = sharedScenario();

    $snapshotId = (string) test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/snapshots", ['label' => 'September close'])
        ->assertCreated()
        ->json('data.id');

    $token = shareLink($setup, 7, $snapshotId);

    // Changing the report afterwards must not change what the link shows.
    test()->actingAs($setup['scenario']->owner)
        ->putJson("/api/reports/{$setup['report']}", [
            'name' => 'Renamed entirely',
            'audience' => 'client_safe',
            'sections' => [['kind' => 'scorecard', 'metrics' => ['revenue']]],
        ])
        ->assertOk();

    $data = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data');

    /*
     * The whole reason a snapshot exists. A client who was sent a figure must either see that figure or be told
     * plainly they are looking at today's.
     */
    expect($data['mode'])->toBe('snapshot');
    expect($data['report']['name'])->toBe('Monthly performance');
});

it('refuses a snapshot that belongs to another report', function (): void {
    $setup = sharedScenario();
    $other = sharedScenario();

    $foreign = (string) test()->actingAs($other['scenario']->owner)
        ->postJson("/api/reports/{$other['report']}/snapshots")
        ->assertCreated()
        ->json('data.id');

    /*
     * Belonging to this report, not merely existing. Without the check a share could serve one client's figures from
     * another client's link — the worst single failure available to this module.
     */
    test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7, 'snapshot_id' => $foreign])
        ->assertNotFound();
});

it('counts views without recording who read what, when', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    foreach (range(1, 3) as $ignored) {
        test()->getJson("/api/shared-reports/{$token}")->assertOk();
    }

    $row = DB::table('report_shares')->where('report_id', $setup['report'])->first();

    /*
     * A count and a last-viewed time, not a per-view table. An agency wants to know whether the client read the
     * report; a log of a client's reading habits is data this product has no reason to hold.
     */
    expect($row)->not->toBeNull();
    /** @var object{view_count: int, last_viewed_at: ?string} $row */
    expect((int) $row->view_count)->toBe(3);
    expect($row->last_viewed_at)->not->toBeNull();
});

it('does not count a view of a link that was refused', function (): void {
    $setup = sharedScenario();
    $token = shareLink($setup);

    DB::table('report_shares')
        ->where('report_id', $setup['report'])
        ->update(['expires_at' => now()->subDay()]);

    test()->getJson("/api/shared-reports/{$token}")->assertOk();

    // Nothing was served, so nothing was read. A count that included refusals would not measure readership.
    $count = DB::table('report_shares')->where('report_id', $setup['report'])->value('view_count');

    expect((int) $count)->toBe(0);
});
