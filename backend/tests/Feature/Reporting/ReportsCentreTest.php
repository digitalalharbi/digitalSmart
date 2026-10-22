<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| The reports centre
|--------------------------------------------------------------------------
|
| What an agency can say about a report's life — made, opened, due to lapse, live or frozen — and every link and
| frozen record across its reports. Counts and times only: nothing here says who looked, or how, or for how long.
|
*/

/** @return array{scenario: WorkspaceScenario, report: string, client: Client} */
function reportsCentreWorld(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $report = test()->actingAs($scenario->owner)->postJson('/api/reports', [
        'name' => 'Monthly performance',
        'client_id' => (string) $client->getKey(),
        'audience' => 'client_safe',
        'window' => 'last_30_days',
        'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
    ])->assertCreated()->json('data.id');

    return ['scenario' => $scenario, 'report' => (string) $report, 'client' => $client];
}

/** @return array<string, mixed> */
function reportsCentreSummary(WorkspaceScenario $scenario, string $report): array
{
    $rows = test()->actingAs($scenario->owner)->getJson('/api/reports')->assertOk()->json('data');

    foreach ($rows as $row) {
        if ($row['id'] === $report) {
            return $row;
        }
    }

    throw new LogicException('Report not listed.');
}

it('says when a report was made and by whom, and nothing about readers', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();

    $summary = reportsCentreSummary($scenario, $report);

    expect($summary['created_at'])->toBeString()
        ->and($summary['created_by_name'])->toBe($scenario->owner->name)
        ->and($summary['view_count'])->toBe(0)
        ->and($summary['last_viewed_at'])->toBeNull()
        ->and($summary['next_expiry_at'])->toBeNull()
        ->and($summary['live_share_count'])->toBe(0)
        ->and($summary['frozen_share_count'])->toBe(0)
        ->and(array_keys($summary))->not->toContain('viewers');
});

it('adds up views over every link, and names the soonest expiry among the active ones', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();

    $short = test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 3])
        ->assertCreated()->json('meta.token');
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 30])->assertCreated();

    test()->getJson("/api/shared-reports/{$short}")->assertOk();
    test()->getJson("/api/shared-reports/{$short}")->assertOk();

    $summary = reportsCentreSummary($scenario, $report);

    expect($summary['view_count'])->toBe(2)
        ->and($summary['last_viewed_at'])->toBeString()
        ->and($summary['live_share_count'])->toBe(2)
        ->and($summary['frozen_share_count'])->toBe(0)
        ->and($summary['active_share_count'])->toBe(2)
        ->and(strtotime($summary['next_expiry_at']))->toBeLessThan(strtotime('+4 days'));
});

it('counts a frozen link apart from a live one, and stops counting a revoked one as active', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();

    $snapshot = test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/snapshots")
        ->assertCreated()->json('data.id');
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7, 'snapshot_id' => $snapshot])
        ->assertCreated();
    $live = test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7])
        ->assertCreated()->json('data.id');

    $summary = reportsCentreSummary($scenario, $report);
    expect($summary['frozen_share_count'])->toBe(1)->and($summary['live_share_count'])->toBe(1);

    test()->actingAs($scenario->owner)->deleteJson("/api/report-shares/{$live}", ['reason' => 'Sent to the wrong person'])
        ->assertSuccessful();

    $after = reportsCentreSummary($scenario, $report);
    expect($after['live_share_count'])->toBe(0)->and($after['active_share_count'])->toBe(1);
});

it('lists every link across the reports the reader may see, with the report each opens', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7, 'recipient_label' => 'Noura'])
        ->assertCreated();

    $rows = test()->actingAs($scenario->owner)->getJson('/api/report-shares')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['report_id'])->toBe($report)
        ->and($rows[0]['report_name'])->toBe('Monthly performance')
        ->and($rows[0]['state'])->toBe('active')
        ->and($rows[0]['recipient_label'])->toBe('Noura')
        ->and($rows[0]['is_frozen'])->toBeFalse()
        ->and(array_keys($rows[0]))->not->toContain('token')
        ->and(strlen($rows[0]['hint']))->toBe(12);
});

it('lists every snapshot across the reports the reader may see', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/snapshots", ['label' => 'Before the call'])
        ->assertCreated();

    $rows = test()->actingAs($scenario->owner)->getJson('/api/report-snapshots')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['report_name'])->toBe('Monthly performance')
        ->and($rows[0]['label'])->toBe('Before the call');
});

it('shows a reader only the links and snapshots of reports they may see', function (): void {
    ['scenario' => $scenario, 'report' => $report] = reportsCentreWorld();
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7])->assertCreated();
    test()->actingAs($scenario->owner)->postJson("/api/reports/{$report}/snapshots")->assertCreated();

    /* A media buyer on no project cannot see this client's report, so cannot see its doors or its records. */
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->getJson('/api/report-snapshots')->assertOk()->assertJsonCount(0, 'data');
    test()->actingAs($buyer)->getJson('/api/report-shares')->assertStatus(Response::HTTP_FORBIDDEN);
});

it('keeps another workspace’s links out of the list', function (): void {
    ['scenario' => $mine, 'report' => $report] = reportsCentreWorld();
    test()->actingAs($mine->owner)->postJson("/api/reports/{$report}/shares", ['days' => 7])->assertCreated();

    $other = WorkspaceScenario::create();

    test()->actingAs($other->owner)->getJson('/api/report-shares')->assertOk()->assertJsonCount(0, 'data');
    test()->actingAs($other->owner)->getJson('/api/report-snapshots')->assertOk()->assertJsonCount(0, 'data');
    expect(DB::table('report_shares')->count())->toBe(1);
});

it('refuses the lists to a client viewer', function (): void {
    ['scenario' => $scenario] = reportsCentreWorld();
    $client = $scenario->addMember(SystemRole::ClientViewer);

    test()->actingAs($client)->getJson('/api/report-shares')->assertStatus(Response::HTTP_FORBIDDEN);
    test()->actingAs($client)->getJson('/api/report-snapshots')->assertStatus(Response::HTTP_FORBIDDEN);
});
