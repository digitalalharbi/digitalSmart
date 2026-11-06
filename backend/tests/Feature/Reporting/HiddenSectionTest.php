<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
| A section can be switched off without being deleted. Hidden means rendered nowhere: the server leaves it out of the
| live view, the shared link, a snapshot and an export, so no client of the API can show what the author switched off.
| The definition — what the builder edits — still holds it.
*/

/** @return array{scenario: WorkspaceScenario, report: string} */
function hiddenSectionScenario(): array
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
            'sections' => [
                ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks'], 'title' => 'Headline figures'],
                ['kind' => 'trend', 'metrics' => ['spend'], 'title' => 'Internal pacing check', 'hidden' => true],
                ['kind' => 'breakdown', 'metrics' => ['spend'], 'dimension' => 'campaign', 'title' => 'By campaign'],
            ],
        ])
        ->assertCreated()
        ->json('data.id');

    return ['scenario' => $scenario, 'report' => (string) $report];
}

/**
 * @param  array<string, mixed>  $report
 * @return list<string>
 */
function hiddenSectionTitles(array $report): array
{
    $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];

    return array_values(array_map(
        static fn (mixed $section): string => is_array($section) && is_string($section['title'] ?? null) ? $section['title'] : '',
        $sections,
    ));
}

it('keeps a hidden section in the definition and says which one it is', function (): void {
    $setup = hiddenSectionScenario();

    $definition = test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$setup['report']}/definition")->assertOk()->json('data');

    expect(array_column($definition['sections'], 'hidden'))->toBe([false, true, false])
        ->and(DB::table('report_sections')->where('report_id', $setup['report'])->where('hidden', true)->count())->toBe(1);
});

it('leaves a hidden section out of the live view, the CSV and a snapshot', function (): void {
    $setup = hiddenSectionScenario();
    $owner = $setup['scenario']->owner;

    $live = test()->actingAs($owner)->getJson("/api/reports/{$setup['report']}")->assertOk()->json('data');
    expect(hiddenSectionTitles($live))->toBe(['Headline figures', 'By campaign']);

    $csv = test()->actingAs($owner)->get("/api/reports/{$setup['report']}/csv")->assertOk()->getContent();
    expect((string) $csv)->toContain('Headline figures');
    expect((string) $csv)->not->toContain('Internal pacing check');

    test()->actingAs($owner)->postJson("/api/reports/{$setup['report']}/snapshots")->assertCreated();
    $payload = (string) DB::table('report_snapshots')->where('report_id', $setup['report'])->value('payload');
    expect($payload)->toContain('Headline figures');
    expect($payload)->not->toContain('Internal pacing check');
});

it('never sends a hidden section through a shared link', function (): void {
    $setup = hiddenSectionScenario();

    $token = (string) test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7])->assertCreated()->json('meta.token');

    auth()->forgetGuards();
    $response = test()->getJson("/api/shared-reports/{$token}")->assertOk();

    $body = (string) $response->getContent();

    expect(hiddenSectionTitles($response->json('data.report')))->toBe(['Headline figures', 'By campaign']);
    expect($body)->not->toContain('Internal pacing check');
    expect($body)->not->toContain('hidden');
});

it('shows the section again at the same link once it is switched back on', function (): void {
    $setup = hiddenSectionScenario();
    $owner = $setup['scenario']->owner;

    $token = (string) test()->actingAs($owner)
        ->postJson("/api/reports/{$setup['report']}/shares", ['days' => 7])->assertCreated()->json('meta.token');

    test()->actingAs($owner)->putJson("/api/reports/{$setup['report']}", [
        'name' => 'Monthly performance',
        'audience' => 'client_safe',
        'sections' => [
            ['kind' => 'scorecard', 'metrics' => ['spend', 'clicks'], 'title' => 'Headline figures'],
            ['kind' => 'trend', 'metrics' => ['spend'], 'title' => 'Internal pacing check', 'hidden' => false],
        ],
    ])->assertOk();

    auth()->forgetGuards();
    expect(hiddenSectionTitles(test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data.report')))
        ->toBe(['Headline figures', 'Internal pacing check']);
});

it('refuses a report whose every section is hidden', function (): void {
    $scenario = WorkspaceScenario::create();

    test()->actingAs($scenario->owner)->postJson('/api/reports', [
        'name' => 'Nothing to see',
        'window' => 'last_30_days',
        'sections' => [['kind' => 'scorecard', 'metrics' => ['spend'], 'hidden' => true]],
    ])->assertStatus(422)->assertJsonPath('code', 'every_section_hidden');
});

it('keeps the hidden flag on a copy', function (): void {
    $setup = hiddenSectionScenario();

    $copy = test()->actingAs($setup['scenario']->owner)
        ->postJson("/api/reports/{$setup['report']}/duplicate")->assertCreated()->json('data.id');

    expect(DB::table('report_sections')->where('report_id', $copy)->orderBy('position')->pluck('hidden')->all())
        ->toBe([false, true, false]);
});
