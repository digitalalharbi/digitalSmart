<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Domain\Reporting\ReportIntent;
use App\Domain\Reporting\SectionKind;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
| What a report is for is stored on the report, and it changes what is shown: an executive report drops the row-by-row
| tables and keeps rankings short. Every report that already exists stays detailed.
*/

/** @return array<string, mixed> */
function intentReportBody(?string $intent): array
{
    return [
        'name' => 'Monthly '.($intent ?? 'default'),
        'window' => 'last_30_days',
        ...($intent === null ? [] : ['intent' => $intent]),
        'sections' => [
            ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks']],
            ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'campaign', 'row_limit' => 20],
            ['kind' => 'table', 'metrics' => ['spend', 'clicks'], 'dimension' => 'campaign'],
        ],
    ];
}

/** More campaigns than an executive ranking holds, each with its own daily figures copied from a real one. */
function intentMoreCampaigns(int $extra): void
{
    $campaign = (array) DB::table('campaigns')->orderBy('id')->first();
    $metrics = DB::table('daily_metrics')->where('entity_type', 'campaign')->where('campaign_id', $campaign['id'])->get();

    for ($i = 0; $i < $extra; $i++) {
        $id = (string) Illuminate\Support\Str::uuid();
        DB::table('campaigns')->insert([...$campaign, 'id' => $id, 'external_id' => 'extra-'.$i, 'name' => 'Extra '.$i]);

        foreach ($metrics as $row) {
            DB::table('daily_metrics')->insert([...(array) $row, 'id' => (string) Illuminate\Support\Str::uuid(), 'campaign_id' => $id, 'entity_id' => $id, 'external_entity_id' => 'extra-'.$i]);
        }
    }
}

it('is detailed unless somebody says otherwise, and keeps every section', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    $id = test()->actingAs($w->scenario->owner)->postJson('/api/reports', intentReportBody(null))
        ->assertCreated()->assertJsonPath('data.intent', 'detailed')->json('data.id');

    $rendered = test()->actingAs($w->scenario->owner)->getJson("/api/reports/{$id}")->assertOk()->json('data');

    expect($rendered['intent'])->toBe('detailed')
        ->and(array_column($rendered['sections'], 'kind'))->toBe(['scorecard', 'breakdown', 'table']);
});

it('drops the drill-down table and caps the ranking in an executive report, and says it is executive', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    intentMoreCampaigns(6);

    $detailed = test()->actingAs($w->scenario->owner)->postJson('/api/reports', intentReportBody('detailed'))->assertCreated()->json('data.id');
    $executive = test()->actingAs($w->scenario->owner)->postJson('/api/reports', intentReportBody('executive'))
        ->assertCreated()->assertJsonPath('data.intent', 'executive')->json('data.id');

    $full = test()->actingAs($w->scenario->owner)->getJson("/api/reports/{$detailed}")->assertOk()->json('data');
    $short = test()->actingAs($w->scenario->owner)->getJson("/api/reports/{$executive}")->assertOk()->json('data');

    expect($short['intent'])->toBe('executive')
        ->and(array_column($short['sections'], 'kind'))->toBe(['scorecard', 'breakdown']);

    $rows = static fn (array $report): int => count($report['sections'][1]['rows'] ?? []);

    expect($rows($full))->toBeGreaterThan(5)->and($rows($short))->toBe(5);
});

it('refuses an intent it does not know and lists the ones it does', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);

    test()->actingAs($w->scenario->owner)->postJson('/api/reports', intentReportBody('whatever'))->assertStatus(422);
    test()->actingAs($w->scenario->owner)->getJson('/api/reports/vocabulary')->assertOk()
        ->assertJsonPath('data.intents.1.intent', 'executive')->assertJsonPath('data.intents.1.row_cap', 5);
});

it('changes on edit without touching the report’s existing snapshots, and a copy keeps the intent', function (): void {
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;

    $id = test()->actingAs($owner)->postJson('/api/reports', intentReportBody('detailed'))->assertCreated()->json('data.id');
    test()->actingAs($owner)->postJson("/api/reports/{$id}/snapshots")->assertCreated();
    $frozen = DB::table('report_snapshots')->where('report_id', $id)->value('payload');

    test()->actingAs($owner)->putJson("/api/reports/{$id}", intentReportBody('executive'))->assertOk()->assertJsonPath('data.intent', 'executive');
    $copy = test()->actingAs($owner)->postJson("/api/reports/{$id}/duplicate")->assertCreated()->json('data.id');

    expect(DB::table('report_snapshots')->where('report_id', $id)->value('payload'))->toBe($frozen)
        ->and(DB::table('reports')->where('id', $copy)->value('intent'))->toBe('executive');
});

it('shows only what the intent allows', function (): void {
    expect(ReportIntent::Executive->shows(SectionKind::Table))->toBeFalse()
        ->and(ReportIntent::Executive->shows(SectionKind::Breakdown))->toBeTrue()
        ->and(ReportIntent::Detailed->shows(SectionKind::Table))->toBeTrue()
        ->and(ReportIntent::Detailed->rowCap())->toBeNull();
});
