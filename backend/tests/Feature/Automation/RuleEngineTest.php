<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/**
 * WHEN … AND … FOR n days THEN …, inside a trust level a person gave the rule.
 *
 * The strongest thing a rule can do is ask: a proposal a person approves, or (at level 3) a task. It never changes a
 * platform, never raises its own trust, and never acts twice in one streak.
 */
function engineWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function makeRule(SyncedWorkspace $w, array $body, int $status = 201): array
{
    return test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules', [
        'name' => 'Rule '.bin2hex(random_bytes(3)),
        'metric' => 'cost_per_result',
        'threshold' => 0.0001,
        ...$body,
    ])->assertStatus($status)->json('data') ?? [];
}

/** @return list<array<string, mixed>> */
function ruleItems(SyncedWorkspace $w): array
{
    return test()->actingAs($w->scenario->owner)->getJson('/api/attention?stream[]=automation')->assertOk()->json('data');
}

it('needs every condition to hold (AND)', function (): void {
    $w = engineWorld();

    makeRule($w, ['name' => 'Impossible', 'conditions' => [['metric' => 'roas', 'threshold' => 0]]]);
    expect(ruleItems($w))->toBe([]);

    makeRule($w, ['name' => 'Both', 'conditions' => [['metric' => 'budget_used', 'threshold' => 0]]]);
    $items = ruleItems($w);
    expect($items)->not->toBeEmpty()
        ->and(array_unique(array_map(static fn (array $i): string => (string) $i['reason']['params']['rule'], $items)))->toBe(['Both']);
});

it('waits until a rule has held for as many days as it asks', function (): void {
    $w = engineWorld();
    $rule = makeRule($w, ['name' => 'Three days', 'for_days' => 3]);

    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();
    expect(ruleItems($w))->toBe([]);

    // Two days ago it was already true, and it still is: three days in a row.
    DB::table('automation_rule_streaks')->where('rule_id', $rule['id'])->update(['first_seen_on' => now()->subDays(2)->toDateString()]);
    expect(ruleItems($w))->not->toBeEmpty();
});

it('proposes a pause at trust 2, once per streak, and changes nothing on the platform', function (): void {
    $w = engineWorld();
    $active = DB::table('campaigns')->where('status', 'active')->count();

    makeRule($w, ['name' => 'Stop the bleeding', 'action' => 'propose_pause', 'trust_level' => 2]);

    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();
    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();

    $proposals = DB::table('approval_requests')->where('agent', 'automation')->get();

    expect($proposals)->not->toBeEmpty()
        ->and($proposals->pluck('state')->unique()->all())->toBe(['pending'])
        // One per campaign, however often the routine runs.
        ->and($proposals->pluck('subject_id')->unique()->count())->toBe($proposals->count())
        ->and((string) $proposals->first()?->rationale)->toContain('Stop the bleeding')
        ->and(DB::table('campaigns')->where('status', 'active')->count())->toBe($active);
});

it('refuses an action beyond the rule’s trust, and level 4 altogether, rather than raising it', function (): void {
    $w = engineWorld();

    makeRule($w, ['action' => 'propose_pause', 'trust_level' => 1], 422);
    makeRule($w, ['action' => 'task', 'trust_level' => 2], 422);
    makeRule($w, ['action' => 'propose_pause', 'trust_level' => 4], 422);
    makeRule($w, ['action' => 'propose_budget_cut', 'trust_level' => 2], 422);

    $cut = makeRule($w, ['action' => 'propose_budget_cut', 'action_pct' => 15, 'trust_level' => 2]);
    expect($cut['trust_level'])->toBe(2)->and($cut['action_pct'])->toBe(15);
    expect(DB::table('automation_rules')->count())->toBe(1);
});

it('watches silently at trust 0 — streaks are kept, nothing is shown', function (): void {
    $w = engineWorld();
    $rule = makeRule($w, ['name' => 'Quiet', 'trust_level' => 0]);

    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();

    expect(ruleItems($w))->toBe([])
        ->and(DB::table('automation_rule_streaks')->where('rule_id', $rule['id'])->count())->toBeGreaterThan(0);
});
