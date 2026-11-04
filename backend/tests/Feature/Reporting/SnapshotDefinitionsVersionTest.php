<?php

declare(strict_types=1);

use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
| A snapshot records which version of the metric definitions its figures were computed under, so a frozen figure that
| today's rules would compute differently says so. A snapshot from before versions were recorded says nothing — unknown
| is neither current nor outdated.
*/

/** @return array{owner: object, report: string} */
function versionedSnapshotReport(): array
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;

    $report = test()->actingAs($owner)->postJson('/api/reports', [
        'name' => 'Monthly', 'window' => 'last_30_days',
        'sections' => [['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']]],
    ])->assertCreated()->json('data.id');

    test()->actingAs($owner)->postJson("/api/reports/{$report}/snapshots")->assertCreated();

    return ['owner' => $owner, 'report' => (string) $report];
}

it('stores the definitions version with a snapshot and reports it as current', function (): void {
    $setup = versionedSnapshotReport();

    test()->actingAs($setup['owner'])->getJson("/api/reports/{$setup['report']}/snapshots")->assertOk()
        ->assertJsonPath('data.0.definitions_version', Metric::DEFINITIONS_VERSION)
        ->assertJsonPath('data.0.definitions_current', true);
});

it('says a snapshot taken under earlier definitions is not current', function (): void {
    $setup = versionedSnapshotReport();
    DB::table('report_snapshots')->where('report_id', $setup['report'])->update(['definitions_version' => '2025-01-01']);

    test()->actingAs($setup['owner'])->getJson("/api/reports/{$setup['report']}/snapshots")->assertOk()
        ->assertJsonPath('data.0.definitions_version', '2025-01-01')
        ->assertJsonPath('data.0.definitions_current', false);
});

it('claims nothing about a snapshot taken before versions were recorded', function (): void {
    $setup = versionedSnapshotReport();
    DB::table('report_snapshots')->where('report_id', $setup['report'])->update(['definitions_version' => null]);

    test()->actingAs($setup['owner'])->getJson("/api/reports/{$setup['report']}/snapshots")->assertOk()
        ->assertJsonPath('data.0.definitions_version', null)
        ->assertJsonPath('data.0.definitions_current', null);
});
