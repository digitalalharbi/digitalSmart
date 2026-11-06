<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
| A tracking link can be edited after people have clicked it. Each state the link was in is kept, with who changed it,
| which values changed and the valid clicks that arrived while it was in force — so a click made before an edit stays
| readable under what it carried, not under what the link says today.
*/

const HISTORY_BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

/** @return array{scenario: WorkspaceScenario, project: Project, link: string, token: string} */
function historyLink(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    $link = test()->actingAs($scenario->owner)->postJson('/api/tracking/links', historyBody($project))->assertCreated()->json('data');

    return ['scenario' => $scenario, 'project' => $project, 'link' => (string) $link['id'], 'token' => basename((string) $link['short_url'])];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function historyBody(Project $project, array $overrides = []): array
{
    return [
        'project_id' => $project->getKey(),
        'name' => 'Snap spring offer',
        'destination_url' => 'https://shop.example.com/offer',
        'platform' => 'snapchat',
        'campaign' => 'Spring Offer',
        'utm' => ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'spring'],
        ...$overrides,
    ];
}

/** @return list<array<string, mixed>> */
function historyOf(WorkspaceScenario $scenario, string $link): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = test()->actingAs($scenario->owner)->getJson("/api/tracking/links/{$link}/history")->assertOk()->json('data');

    return $rows;
}

it('starts a link at revision one, recorded and not backfilled', function (): void {
    $setup = historyLink();
    $history = historyOf($setup['scenario'], $setup['link']);

    expect($history)->toHaveCount(1)
        ->and($history[0]['revision'])->toBe(1)
        ->and($history[0]['is_current'])->toBeTrue()
        ->and($history[0]['backfilled'])->toBeFalse()
        ->and($history[0]['changed_fields'])->toBe([])
        ->and($history[0]['values']['utm_campaign'])->toBe('spring');
});

it('writes a revision naming what changed and who changed it, and keeps the earlier values', function (): void {
    $setup = historyLink();

    test()->actingAs($setup['scenario']->owner)
        ->putJson("/api/tracking/links/{$setup['link']}", historyBody($setup['project'], [
            'utm' => ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'summer'],
            'destination_url' => 'https://shop.example.com/summer',
        ]))->assertOk();

    $history = historyOf($setup['scenario'], $setup['link']);

    expect($history)->toHaveCount(2)
        ->and($history[0]['revision'])->toBe(2)
        ->and($history[0]['is_current'])->toBeTrue()
        ->and($history[0]['changed_fields'])->toEqualCanonicalizing(['destination_url', 'utm_campaign'])
        ->and($history[0]['changed_by_name'])->toBe($setup['scenario']->owner->name)
        ->and($history[0]['values']['utm_campaign'])->toBe('summer')
        ->and($history[1]['is_current'])->toBeFalse()
        ->and($history[1]['effective_until'])->not->toBeNull()
        ->and($history[1]['values']['utm_campaign'])->toBe('spring')
        ->and($history[1]['values']['destination_url'])->toBe('https://shop.example.com/offer');
});

it('writes nothing when a save changes only what the team reads', function (): void {
    $setup = historyLink();

    test()->actingAs($setup['scenario']->owner)
        ->putJson("/api/tracking/links/{$setup['link']}", historyBody($setup['project'], ['name' => 'Renamed', 'notes' => 'For the spring flight']))
        ->assertOk();

    expect(historyOf($setup['scenario'], $setup['link']))->toHaveCount(1);
});

it('counts a click under the values the link carried when it arrived', function (): void {
    $setup = historyLink();

    test()->withHeaders(['User-Agent' => HISTORY_BROWSER])->get("/api/r/{$setup['token']}")->assertRedirect();
    DB::table('tracking_sessions')->where('tracking_link_id', $setup['link'])->update(['started_at' => now()->subHour()]);
    DB::table('tracking_link_revisions')->where('tracking_link_id', $setup['link'])->update(['effective_from' => now()->subHours(2)]);

    test()->actingAs($setup['scenario']->owner)
        ->putJson("/api/tracking/links/{$setup['link']}", historyBody($setup['project'], [
            'utm' => ['utm_source' => 'snapchat', 'utm_medium' => 'paid_social', 'utm_campaign' => 'summer'],
        ]))->assertOk();

    auth()->forgetGuards();
    test()->withHeaders(['User-Agent' => HISTORY_BROWSER])->get("/api/r/{$setup['token']}")->assertRedirect();
    test()->withHeaders(['User-Agent' => HISTORY_BROWSER])->get("/api/r/{$setup['token']}")->assertRedirect();

    $history = historyOf($setup['scenario'], $setup['link']);

    expect($history[0]['values']['utm_campaign'])->toBe('summer')
        ->and($history[0]['clicks'])->toBe(2)
        ->and($history[1]['values']['utm_campaign'])->toBe('spring')
        ->and($history[1]['clicks'])->toBe(1);
});

it('is closed to somebody who cannot see the project, and to another workspace', function (): void {
    $setup = historyLink();
    $buyer = $setup['scenario']->addMember(SystemRole::MediaBuyer);

    test()->actingAs($buyer)->getJson("/api/tracking/links/{$setup['link']}/history")->assertNotFound();

    $other = WorkspaceScenario::create();
    auth()->forgetGuards();
    test()->actingAs($other->owner)->getJson("/api/tracking/links/{$setup['link']}/history")->assertNotFound();
});
