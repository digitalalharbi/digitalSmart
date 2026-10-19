<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/**
 * The automated-rules operating system: modes, AND/OR, operators, scope, schedule, cooldown, conflict, capability,
 * dry run, and the history, summary and change log a person reads — inside the reader's own projects.
 */
function osWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function osRule(SyncedWorkspace $w, array $body, int $status = 201): array
{
    return test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules', [
        'name' => 'Rule '.Str::random(6),
        'metric' => 'cost_per_result',
        'threshold' => 0.0001,
        ...$body,
    ])->assertStatus($status)->json('data') ?? [];
}

function osRun(SyncedWorkspace $w): void
{
    test()->artisan('automation:run', ['--workspace' => $w->workspaceId(), '--force' => true])->assertSuccessful();
}

it('refuses Auto with the reason, and never stores it', function (): void {
    $w = osWorld();

    $refused = test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules', [
        'name' => 'Autopilot', 'metric' => 'roas', 'threshold' => 1, 'action' => 'propose_pause', 'mode' => 'auto',
    ])->assertStatus(422);

    expect((string) $refused->json('message'))->toContain('Auto-execute is not authorised')
        ->and(DB::table('automation_rules')->count())->toBe(0);
});

it('monitors without proposing, and recommends by raising a proposal with the figures before and after', function (): void {
    $w = osWorld();
    $monitor = osRule($w, ['name' => 'Watch', 'mode' => 'monitor', 'action' => 'alert']);
    $recommend = osRule($w, ['name' => 'Stop', 'mode' => 'recommend', 'action' => 'propose_pause']);

    osRun($w);

    $watched = DB::table('automation_rule_executions')->where('rule_id', $monitor['id'])->get();
    $stopped = DB::table('automation_rule_executions')->where('rule_id', $recommend['id'])->get();

    expect($watched)->not->toBeEmpty()
        ->and($watched->pluck('result')->unique()->all())->toBe(['completed'])
        ->and($watched->pluck('action')->unique()->all())->toBe(['notify'])
        ->and($watched->whereNotNull('approval_id')->count())->toBe(0)
        ->and($stopped->pluck('result')->unique()->all())->toBe(['waiting_approval'])
        ->and($stopped->whereNull('approval_id')->count())->toBe(0);

    $first = $stopped->first();
    expect(json_decode((string) $first?->before, true))->toHaveKeys(['cost_per_result', 'status'])
        ->and(json_decode((string) $first?->after, true))->toBe(['status' => 'paused'])
        // A proposal is only a request: nothing on the platform moved.
        ->and(DB::table('campaigns')->where('id', $first?->campaign_id)->value('status'))->toBe('active');
});

it('combines conditions with AND or OR, each with its own direction', function (): void {
    $w = osWorld();
    $simulate = static fn (array $body): int => (int) test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/simulate', [
        'name' => 'Draft', 'metric' => 'cost_per_result', 'threshold' => 0.0001, ...$body,
    ])->assertOk()->json('data.matched');

    $impossible = ['metric' => 'spend', 'operator' => 'below', 'threshold' => 0];

    expect($simulate(['conditions' => [$impossible], 'logic' => 'all']))->toBe(0)
        ->and($simulate(['conditions' => [$impossible], 'logic' => 'any']))->toBeGreaterThan(0)
        // "ROAS above" — a scale-winners rule — reads the other way from the default "ROAS below".
        ->and($simulate(['metric' => 'roas', 'operator' => 'above', 'threshold' => 0]))->toBeGreaterThan(0)
        ->and($simulate(['metric' => 'roas', 'operator' => 'below', 'threshold' => 0]))->toBe(0);
});

it('dry-runs a draft without writing anything, and says what each platform could take', function (): void {
    $w = osWorld();
    $before = [DB::table('approval_requests')->count(), DB::table('automation_rule_executions')->count(), DB::table('automation_rules')->count()];

    $run = test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/simulate', [
        'name' => 'Bid', 'metric' => 'cost_per_result', 'threshold' => 0.0001, 'action' => 'propose_bid_change', 'action_pct' => 10,
    ])->assertOk()->json('data');

    expect($run['matched'])->toBeGreaterThan(0)
        ->and(array_unique(array_column($run['objects'], 'capability')))->toBe(['unsupported:BID_WRITE_NOT_VERIFIED'])
        ->and([DB::table('approval_requests')->count(), DB::table('automation_rule_executions')->count(), DB::table('automation_rules')->count()])->toBe($before);
});

it('keeps to its scope: a platform with no campaigns here matches nothing, another workspace’s campaign is not found', function (): void {
    $w = osWorld();
    $other = osWorld();

    expect((int) test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/simulate', [
        'name' => 'Google only', 'metric' => 'cost_per_result', 'threshold' => 0.0001, 'providers' => ['google_ads'],
    ])->assertOk()->json('data.matched'))->toBe(0);

    $foreign = (string) DB::table('campaigns')->where('workspace_id', $other->workspaceId())->value('id');
    osRule($w, ['campaign_ids' => [$foreign]], 404);
});

it('records a bid change as unsupported and never attempts it', function (): void {
    $w = osWorld();
    $rule = osRule($w, ['action' => 'propose_bid_change', 'action_pct' => 10]);

    osRun($w);

    $rows = DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->get();
    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('result')->unique()->all())->toBe(['unsupported'])
        ->and($rows->pluck('reason_code')->unique()->all())->toBe(['BID_WRITE_NOT_VERIFIED'])
        ->and(DB::table('approval_requests')->count())->toBe(0);
});

it('waits out its cooldown, and stands down when another rule moved the same campaign the other way', function (): void {
    $w = osWorld();
    $raise = osRule($w, ['name' => 'Scale', 'action' => 'propose_budget_increase', 'action_pct' => 10, 'cooldown_hours' => 24]);
    osRun($w);

    $raised = DB::table('automation_rule_executions')->where('rule_id', $raise['id'])->where('result', 'waiting_approval')->pluck('campaign_id')->all();
    expect($raised)->not->toBeEmpty();

    // A new streak inside the cooldown does not act again.
    DB::table('automation_rule_streaks')->update(['acted_at' => null, 'first_seen_on' => now()->subDays(3)->toDateString()]);
    $count = DB::table('automation_rule_executions')->where('rule_id', $raise['id'])->whereIn('campaign_id', $raised)->count();
    osRun($w);
    expect(DB::table('automation_rule_executions')->where('rule_id', $raise['id'])->whereIn('campaign_id', $raised)->count())->toBe($count);

    // A rule that would cut those budgets stands down: it does not undo the other rule.
    $cut = osRule($w, ['name' => 'Trim', 'action' => 'propose_budget_cut', 'action_pct' => 10, 'campaign_ids' => [$raised[0]]]);
    osRun($w);

    $trim = DB::table('automation_rule_executions')->where('rule_id', $cut['id'])->sole();
    expect($trim->result)->toBe('no_change')->and($trim->reason_code)->toBe('CONFLICT');
});

it('runs a daily rule once a day at its time in its own time zone', function (): void {
    $w = osWorld();
    $rule = osRule($w, ['schedule' => 'daily', 'schedule_time' => '23:59', 'timezone' => 'Pacific/Kiritimati']);

    // Not forced: the routine decides. The rule's slot today has either passed (it runs once) or not (it waits).
    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();
    $first = DB::table('automation_rules')->where('id', $rule['id'])->value('last_run_at');
    test()->artisan('automation:run', ['--workspace' => $w->workspaceId()])->assertSuccessful();

    expect(DB::table('automation_rules')->where('id', $rule['id'])->value('last_run_at'))->toBe($first);
});

it('counts actions for the summary, lists them in the history, and logs every change with who and what', function (): void {
    $w = osWorld();
    $rule = osRule($w, ['name' => 'Stop losers', 'action' => 'propose_pause']);
    osRun($w);

    test()->actingAs($w->scenario->owner)->putJson("/api/automation/rules/{$rule['id']}", [
        'name' => 'Stop losers', 'metric' => 'cost_per_result', 'threshold' => 0.0001, 'action' => 'propose_pause', 'mode' => 'monitor', 'is_active' => false,
    ])->assertOk();

    $summary = test()->actingAs($w->scenario->owner)->getJson('/api/automation/summary?days=7')->assertOk()->json('data');
    $history = test()->actingAs($w->scenario->owner)->getJson('/api/automation/history?days=7')->assertOk()->json('data');
    $changes = test()->actingAs($w->scenario->owner)->getJson('/api/automation/changes?days=7')->assertOk()->json('data');

    expect($summary['cards']['turn_off']['now'])->toBeGreaterThan(0)
        ->and($summary['cards']['all']['now'])->toBe(count($history))
        ->and($history[0])->toHaveKeys(['at', 'rule', 'client', 'project', 'provider', 'object', 'action', 'before', 'after', 'result'])
        ->and(array_merge(...array_column($changes, 'kinds')))->toContain('created', 'disabled', 'mode_changed')
        ->and($changes[0]['who'])->not->toBeNull();
});

it('shows a reader limited to some projects only their actions', function (): void {
    $w = osWorld();
    osRule($w, ['action' => 'propose_pause']);
    osRun($w);

    $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);

    expect(test()->actingAs($buyer)->getJson('/api/automation/history?days=7')->assertOk()->json('data'))->toBe([])
        ->and(test()->actingAs($buyer)->getJson('/api/automation/summary?days=7')->assertOk()->json('data.cards.all.now'))->toBe(0);
});

it('says which actions each platform supports, and which await credentials', function (): void {
    $w = osWorld();
    $by = array_column(test()->actingAs($w->scenario->owner)->getJson('/api/automation/capabilities')->assertOk()->json('data'), null, 'provider');

    expect($by['meta']['actions']['propose_pause'])->toBe('supported')
        ->and($by['meta']['actions']['propose_bid_change'])->toBe('unsupported')
        ->and($by['tiktok']['actions']['propose_budget_cut'])->toBe('unsupported')
        ->and($by['meta']['credentials'])->toBe('connected')
        ->and($by['linkedin']['credentials'])->toBe('awaiting_credentials');
});
