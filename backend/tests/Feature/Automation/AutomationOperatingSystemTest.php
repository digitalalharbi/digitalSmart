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
        ->and(array_unique(array_column($run['objects'], 'capability')))->toBe(['unsupported'])
        ->and($run['skipped'])->toBe(['unsupported' => $run['matched']])
        ->and($run['would_act'])->toBe(0)
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

it('never promises a bid change: refused on save, and named unsupported in the dry run', function (): void {
    $w = osWorld();

    $refused = osRule($w, ['action' => 'propose_bid_change', 'action_pct' => 10], 422);
    expect(DB::table('automation_rules')->count())->toBe(0)->and($refused)->toBe([]);

    // A rule naming only platforms that cannot take its action is refused too.
    osRule($w, ['action' => 'propose_pause', 'providers' => ['tiktok', 'x']], 422);
});

it('waits out its cooldown, and stands down when another rule moved the same campaign the other way', function (): void {
    $w = osWorld();
    $raise = osRule($w, ['name' => 'Scale', 'action' => 'propose_budget_increase', 'action_pct' => 10, 'cooldown_hours' => 24]);
    osRun($w);

    $raised = DB::table('automation_rule_executions')->where('rule_id', $raise['id'])->where('result', 'waiting_approval')->pluck('campaign_id')->all();
    expect($raised)->not->toBeEmpty();

    // A new streak inside the cooldown does not act again: it is recorded once as a cooldown, and nothing is proposed.
    DB::table('automation_rule_streaks')->update(['acted_at' => null, 'first_seen_on' => now()->subDays(3)->toDateString()]);
    $proposals = DB::table('approval_requests')->count();
    osRun($w);
    osRun($w);

    $cooling = DB::table('automation_rule_executions')->where('rule_id', $raise['id'])->whereIn('campaign_id', $raised)->where('result', 'cooldown')->get();
    expect($cooling)->toHaveCount(count($raised))
        ->and($cooling->pluck('reason_code')->unique()->all())->toBe(['COOLDOWN'])
        ->and(DB::table('approval_requests')->count())->toBe($proposals);

    // A rule that would cut those budgets stands down: it does not undo the other rule.
    $cut = osRule($w, ['name' => 'Trim', 'action' => 'propose_budget_cut', 'action_pct' => 10, 'campaign_ids' => [$raised[0]]]);
    osRun($w);

    $trim = DB::table('automation_rule_executions')->where('rule_id', $cut['id'])->sole();
    expect($trim->result)->toBe('conflict')->and($trim->reason_code)->toBe('CONFLICT')
        // And nothing was proposed by the rule that stood down.
        ->and(DB::table('approval_requests')->where('agent', 'automation')->where('subject_id', $raised[0])->count())->toBe(1);
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
        ->and($by['tiktok']['actions']['propose_budget_cut'])->toBe('unverified')
        ->and($by['meta']['credentials'])->toBe('connected')
        ->and($by['linkedin']['credentials'])->toBe('awaiting_credentials');
});

it('judges nothing without enough data, and says so in the dry run', function (): void {
    $w = osWorld();
    $simulate = static fn (array $body): array => test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/simulate', [
        'name' => 'Draft', 'metric' => 'cost_per_result', 'threshold' => 0.0001, ...$body,
    ])->assertOk()->json('data');

    $plain = $simulate([]);
    $strict = $simulate(['min_spend' => 100000000, 'min_age_days' => 0]);

    expect($plain['matched'])->toBeGreaterThan(0)
        ->and($strict['matched'])->toBe(0)
        ->and($strict['insufficient'])->toBe($strict['looked_at']);
});

it('names each platform\'s capability honestly: supported, awaiting credentials, unverified, unsupported', function (): void {
    $w = osWorld();
    $by = array_column(test()->actingAs($w->scenario->owner)->getJson('/api/automation/capabilities')->assertOk()->json('data'), null, 'provider');

    expect($by['meta']['actions']['propose_pause'])->toBe('supported')
        ->and($by['google_ads']['actions']['propose_pause'])->toBe('awaiting_credentials')
        ->and($by['tiktok']['actions']['propose_pause'])->toBe('unverified')
        ->and($by['meta']['actions']['propose_bid_change'])->toBe('unsupported')
        ->and($by['meta']['actions']['alert'])->toBe('supported');
});

it('enables, disables, archives and changes mode in bulk — each rule through its own save, each change in the log', function (): void {
    $w = osWorld();
    $a = osRule($w, ['name' => 'Bulk A', 'action' => 'propose_pause']);
    $b = osRule($w, ['name' => 'Bulk B', 'action' => 'alert', 'mode' => 'monitor']);
    $bulk = static fn (array $body): array => test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/bulk', $body)->assertOk()->json('data');

    expect($bulk(['ids' => [$a['id'], $b['id']], 'operation' => 'disable'])['done'])->toBe(2)
        ->and(DB::table('automation_rules')->whereIn('id', [$a['id'], $b['id']])->where('is_active', true)->count())->toBe(0);

    expect($bulk(['ids' => [$a['id']], 'operation' => 'mode', 'mode' => 'monitor'])['done'])->toBe(1)
        ->and(DB::table('automation_rules')->where('id', $a['id'])->value('mode'))->toBe('monitor');

    // Auto cannot be asked for in bulk.
    test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/bulk', ['ids' => [$a['id']], 'operation' => 'mode', 'mode' => 'auto'])->assertStatus(422);

    expect($bulk(['ids' => [$b['id']], 'operation' => 'archive'])['done'])->toBe(1)
        ->and(DB::table('automation_rules')->where('id', $b['id'])->value('archived_at'))->not->toBeNull();

    $kinds = array_merge(...array_column(test()->actingAs($w->scenario->owner)->getJson('/api/automation/changes?days=7')->json('data'), 'kinds'));
    expect($kinds)->toContain('disabled', 'mode_changed', 'archived');

    // Someone who cannot manage rules changes none of them.
    $buyer = $w->scenario->addMember(SystemRole::ClientViewer);
    test()->actingAs($buyer)->postJson('/api/automation/rules/bulk', ['ids' => [$a['id']], 'operation' => 'enable'])->assertOk()->assertJsonPath('data.done', 0);
});

it('puts what the rules did into the digest, as counts — and says when proposals wait for the reader', function (): void {
    $w = osWorld();
    osRule($w, ['name' => 'Digest stop', 'action' => 'propose_pause']);
    osRun($w);

    test()->artisan('digests:build', ['kind' => 'morning_campaigns'])->assertSuccessful();

    $digest = DB::table('digests')->where('user_id', $w->scenario->owner->getKey())->latest('created_at')->first();
    expect($digest)->not->toBeNull();

    $rules = json_decode((string) $digest?->summary, true)['rules'];
    expect($rules['triggered'])->toBeGreaterThan(0)
        ->and($rules['waiting_approval'])->toBe($rules['triggered'])
        ->and($rules['days'])->toBe(1);

    expect((string) DB::table('notifications')->where('user_id', $w->scenario->owner->getKey())->where('kind', 'digest_ready')->latest('created_at')->value('title'))
        ->toContain('waiting your approval');
});
