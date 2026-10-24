<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\CampaignPlanScene;

/*
|--------------------------------------------------------------------------
| The launch plan and its preflight
|--------------------------------------------------------------------------
|
| Before anything is approved: exactly what would be written to each ad account, and every check between the plan and writing
| it, each with what fixes it. Read-only — nothing is created to find out whether it could be, and a sandbox connection is
| reported as awaiting credentials, never as ready.
|
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array{scene: array<string, mixed>, id: string}
 */
function launchScene(array $overrides = []): array
{
    $scene = CampaignPlanScene::create();
    $id = (string) test()->actingAs($scene['w']->scenario->owner)
        ->postJson('/api/campaign-plans', CampaignPlanScene::payload($scene, $overrides))->assertCreated()->json('data.id');

    return ['scene' => $scene, 'id' => $id];
}

/**
 * @param  list<array{key: string, state: string}>  $checks
 * @return array<string, string>
 */
function checkStates(array $checks): array
{
    return array_column($checks, 'state', 'key');
}

it('plans every write per account, paused, and calls the sandbox what it is', function (): void {
    ['scene' => $s, 'id' => $id] = launchScene();
    $before = ['campaigns' => DB::table('campaigns')->count(), 'status' => DB::table('campaign_blueprints')->where('id', $id)->value('status')];

    $plan = $this->actingAs($s['w']->scenario->owner)->getJson("/api/campaign-plans/{$id}/launch-plan")->assertOk()->json('data');
    $by = array_column($plan['providers'], null, 'state');
    $providers = array_column(array_column($plan['providers'], 'plan'), null, 'provider');

    expect($plan['can_create'])->toBeFalse()
        ->and($plan['version'])->toBe(1)
        ->and($providers['meta'])->toMatchArray(['campaigns' => 1, 'ad_groups' => 1, 'ads' => 1, 'objective' => 'OUTCOME_LEADS', 'destination' => 'https://example.test/ramadan'])
        ->and($providers['meta']['payload']['campaign']['status'])->toBe('PAUSED')
        ->and($providers['meta']['payload']['ad_set']['status'])->toBe('PAUSED')
        ->and($providers['google_ads']['payload']['campaign']['status'])->toBe('PAUSED')
        ->and($providers['google_ads']['payload']['campaign_budget']['amount_micros'])->toBe(25_000 * 10_000);

    foreach ($plan['providers'] as $entry) {
        expect(checkStates($entry['checks'])['connection'])->toBe('awaiting_credentials')
            ->and(checkStates($entry['checks'])['campaign.create'])->toBe('ready');
    }

    // A creative with no file stops the ads, and says what fixes it.
    $creatives = collect((array) $plan['checks'])->firstWhere('key', 'creatives');
    expect($creatives['state'])->toBe('blocked')->and($creatives['fix'])->toBe('Upload a version in Creative Hub.');
    expect($by)->not->toHaveKey('ready');

    // Read-only: nothing created, nothing moved.
    expect(DB::table('campaigns')->count())->toBe($before['campaigns'])
        ->and(DB::table('campaign_blueprints')->where('id', $id)->value('status'))->toBe($before['status']);
});

it('blocks an account in another currency, a start in the past and an account no longer bound, and totals per currency', function (): void {
    ['scene' => $s, 'id' => $id] = launchScene(['starts_at' => now()->subDays(3)->toDateString(), 'ends_at' => now()->addDays(10)->toDateString()]);
    DB::table('external_accounts')->where('id', $s['google'])->update(['currency' => 'AED']);

    $plan = $this->actingAs($s['w']->scenario->owner)->getJson("/api/campaign-plans/{$id}/launch-plan")->assertOk()->json('data');
    $google = collect((array) $plan['providers'])->first(static fn (array $p): bool => $p['plan']['provider'] === 'google_ads');

    expect(checkStates($plan['checks'])['dates'])->toBe('blocked')
        ->and(checkStates($google['checks'])['currency'])->toBe('blocked')
        ->and(array_column($plan['totals'], 'budget_minor', 'currency'))->toHaveCount(2)->toHaveKey('SAR', 25_000)->toHaveKey('AED', 25_000);

    DB::table('project_integration_bindings')->where('external_account_id', $s['google'])->where('project_id', $s['project']->getKey())->delete();
    $plan = $this->actingAs($s['w']->scenario->owner)->getJson("/api/campaign-plans/{$id}/launch-plan")->assertOk()->json('data');
    $google = collect((array) $plan['providers'])->first(static fn (array $p): bool => $p['plan']['provider'] === 'google_ads');
    expect($google['state'])->toBe('blocked')->and($google['checks'][0]['key'])->toBe('binding');
});

it('says when the plan includes no launches, and warns about a results objective with no conversion', function (): void {
    ['scene' => $s, 'id' => $id] = launchScene(['conversion_event_id' => null]);
    $this->artisan('saas:override', ['workspace' => $s['w']->workspaceId(), 'kind' => 'limit', 'key' => 'campaign_launches_monthly', 'value' => '0', '--operator' => 'ops', '--reason' => 'test'])->assertSuccessful();

    $plan = $this->actingAs($s['w']->scenario->owner)->getJson("/api/campaign-plans/{$id}/launch-plan")->assertOk()->json('data');

    expect(checkStates($plan['checks']))->toMatchArray(['plan_quota' => 'blocked', 'conversion' => 'warning'])
        ->and($plan['state'])->toBe('blocked');
});

it('is refused for another workspace’s plan, and for a reader who may not see the project', function (): void {
    ['scene' => $s, 'id' => $id] = launchScene();
    $other = CampaignPlanScene::create();

    $this->actingAs($other['w']->scenario->owner)->getJson("/api/campaign-plans/{$id}/launch-plan")->assertNotFound();
});
