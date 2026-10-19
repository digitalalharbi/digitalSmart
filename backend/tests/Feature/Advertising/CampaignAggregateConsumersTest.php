<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/**
 * Every reader of the shared campaign aggregate sees a campaign's own rows only. `CampaignFiguresLevelTest` pins the
 * aggregate itself; this pins each place that consumes it — the portfolio, the rule engine's dry run and the agents'
 * campaign tool — so a consumer that re-sums metrics on its own cannot bring the double count back.
 */
function duplicateBelowCampaignLevel(): void
{
    foreach (DB::table('daily_metrics')->where('entity_type', 'campaign')->get() as $row) {
        foreach (['ad_group', 'ad'] as $level) {
            DB::table('daily_metrics')->insert([...(array) $row, 'id' => (string) Str::uuid(), 'entity_type' => $level, 'entity_id' => (string) Str::uuid()]);
        }
    }
}

/**
 * @param  Closure(SyncedWorkspace): mixed  $read
 */
function sameBeforeAndAfterDuplication(Closure $read): void
{
    $w = SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
    DB::table('campaigns')->update(['status' => 'active']);

    $before = $read($w);
    expect($before)->not->toBeEmpty();

    duplicateBelowCampaignLevel();

    expect($read($w))->toEqual($before);
}

it('keeps the portfolio totals at campaign level', function (): void {
    sameBeforeAndAfterDuplication(static fn (SyncedWorkspace $w): mixed => test()->actingAs($w->scenario->owner)
        ->getJson('/api/portfolio')->assertOk()->json('data.kpis'));
});

it('judges rules on campaign-level figures: the dry run matches the same campaigns with the same numbers', function (): void {
    sameBeforeAndAfterDuplication(static fn (SyncedWorkspace $w): mixed => test()->actingAs($w->scenario->owner)
        ->postJson('/api/automation/rules/simulate', [
            'name' => 'Spend check', 'metric' => 'spend', 'threshold' => 0.01, 'logic' => 'all',
            'conditions' => [['metric' => 'spend', 'operator' => 'above', 'threshold' => 0]],
        ])->assertOk()->json('data'));
});

it('hands the agents campaign-level figures through the campaign tool', function (): void {
    sameBeforeAndAfterDuplication(static fn (SyncedWorkspace $w): mixed => test()->actingAs($w->scenario->owner)
        ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_campaigns', 'arguments' => []]])
        ->assertOk()->json('result'));
});
