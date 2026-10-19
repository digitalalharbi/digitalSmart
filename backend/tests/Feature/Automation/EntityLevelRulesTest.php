<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SyncedWorkspace;

/**
 * Rules one and two levels below a campaign: an ad group (a Meta ad set, an X line item) and an ad. Each is judged on
 * its own metric rows, acts only where its platform documents that change **at that level**, and proposes through the
 * same approval as a campaign — named by its own platform id, so the carry-out step changes that entity and no other.
 */
function entityWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001', Provider::X->value => '18ce54d4x5t']);
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function entityRule(SyncedWorkspace $w, array $body, int $status = 201): array
{
    return test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules', [
        'name' => 'Rule '.Str::random(6),
        'metric' => 'spend',
        'threshold' => 0.01,
        ...$body,
    ])->assertStatus($status)->json('data') ?? [];
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function entityDryRun(SyncedWorkspace $w, array $body): array
{
    return test()->actingAs($w->scenario->owner)->postJson('/api/automation/rules/simulate', [
        'name' => 'Draft',
        'metric' => 'spend',
        'threshold' => 0.01,
        ...$body,
    ])->assertOk()->json('data');
}

function entityRun(): void
{
    Artisan::call('automation:run', ['--force' => true]);
}

/** The sum of one entity's own rows over a rule's default window — what the engine must report, and nothing else. */
function ownSpend(string $level, string $id, int $days = 7): float
{
    $to = Carbon::now()->subDay()->toDateString();
    $from = Carbon::now()->subDay()->subDays($days - 1)->toDateString();
    $sum = DB::table('daily_metrics')->where(['entity_type' => $level, 'entity_id' => $id])
        ->whereBetween('date', [$from, $to])->sum('spend_minor');

    return round((int) $sum / 100, 2);
}

/** Every campaign-level and ad-group-level row copied once more one level down — the double count, ready to happen. */
/** One row a test needs to exist — a failure here names the missing row rather than a null property. */
function entityFirst(Builder $query): stdClass
{
    $row = $query->first();
    expect($row)->not->toBeNull();
    assert($row instanceof stdClass);

    return $row;
}

function duplicateAcrossLevels(): void
{
    foreach (DB::table('daily_metrics')->whereIn('entity_type', ['campaign', 'ad_group'])->get() as $row) {
        DB::table('daily_metrics')->insert([...(array) $row, 'id' => (string) Str::uuid(), 'entity_type' => $row->entity_type === 'campaign' ? 'ad_group' : 'ad', 'entity_id' => (string) Str::uuid()]);
    }
}

describe('entity metric truth', function (): void {
    it('judges each ad group on its own rows only, never its campaign\'s or its ads\'', function (): void {
        $w = entityWorld();
        $draft = ['object_level' => 'ad_group', 'providers' => ['meta'], 'action' => 'alert'];

        $before = entityDryRun($w, $draft)['objects'];
        expect($before)->not->toBeEmpty();

        foreach ($before as $object) {
            expect($object['level'])->toBe('ad_group')
                ->and($object['values']['spend'])->toBe(ownSpend('ad_group', $object['entity_id']));
        }

        duplicateAcrossLevels();

        expect(array_column(entityDryRun($w, $draft)['objects'], 'values', 'entity_id'))
            ->toEqual(array_column($before, 'values', 'entity_id'));
    });

    it('judges each ad on its own rows only', function (): void {
        $w = entityWorld();
        $draft = ['object_level' => 'ad', 'providers' => ['meta'], 'action' => 'alert'];

        $before = entityDryRun($w, $draft)['objects'];
        expect($before)->not->toBeEmpty();

        foreach ($before as $object) {
            expect($object['level'])->toBe('ad')
                ->and($object['values']['spend'])->toBe(ownSpend('ad', $object['entity_id']))
                // Named with its parents, so a reader never needs an id.
                ->and($object['parent'])->toContain(' › ');
        }

        duplicateAcrossLevels();

        expect(array_column(entityDryRun($w, $draft)['objects'], 'values', 'entity_id'))
            ->toEqual(array_column($before, 'values', 'entity_id'));
    });

    it('keeps campaign figures at campaign level when rows are added below', function (): void {
        $w = entityWorld();
        $draft = ['object_level' => 'campaign', 'providers' => ['meta'], 'action' => 'alert'];

        $before = entityDryRun($w, $draft)['objects'];
        expect($before)->not->toBeEmpty();

        duplicateAcrossLevels();

        expect(array_column(entityDryRun($w, $draft)['objects'], 'values', 'entity_id'))
            ->toEqual(array_column($before, 'values', 'entity_id'));
    });
});

describe('entity rules', function (): void {
    it('proposes pausing an ad set by its own id, through the same approval, and the sandbox carries out exactly that', function (): void {
        $w = entityWorld();
        $rule = entityRule($w, ['object_level' => 'ad_group', 'providers' => ['meta'], 'action' => 'propose_pause']);

        entityRun();

        $row = entityFirst(DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('result', 'waiting_approval'));
        expect($row)->not->toBeNull()
            ->and($row->object_level)->toBe('ad_group')
            ->and($row->parent_label)->not->toBeNull();

        $group = entityFirst(DB::table('ad_groups')->where('id', $row->entity_id));
        $approval = test()->actingAs($w->scenario->owner)->getJson("/api/approvals/{$row->approval_id}")->assertOk()->json('data');

        expect($approval['kind'])->toBe('campaign_pause')
            ->and($approval['proposal']['level'])->toBe('ad_group')
            ->and($approval['proposal']['external_ad_group_id'])->toBe($group->external_id)
            ->and($approval['can_be_carried_out_here'])->toBeTrue()
            // Proposing is not doing.
            ->and($group->status)->toBe('active');

        test()->actingAs($w->scenario->owner)->postJson("/api/approvals/{$row->approval_id}/decision", ['outcome' => 'approved', 'fingerprint' => $approval['fingerprint']])->assertOk();
        $done = test()->actingAs($w->scenario->owner)->postJson("/api/approvals/{$row->approval_id}/carry-out")->assertOk();

        expect($done->json('outcome.is_confirmed'))->toBeTrue()
            ->and($done->json('outcome.confirmed_state'))->toBe('PAUSED')
            ->and($done->json('outcome.source'))->toBe('sandbox');

        $attempt = json_decode((string) DB::table('audit_logs')->where('action', 'provider.write_attempted')->latest('created_at')->value('changes'), true);
        expect($attempt['operation'])->toBe('pause_ad_group')
            ->and($attempt['level'])->toBe('ad_group')
            ->and($attempt['external_entity_id'])->toBe($group->external_id)
            ->and($attempt['reference'])->toContain('/{adset_id}');
    });

    it('proposes pausing an ad, named by its own id, its ad set and campaign named for a person', function (): void {
        $w = entityWorld();
        $rule = entityRule($w, ['object_level' => 'ad', 'providers' => ['meta'], 'action' => 'propose_pause']);

        entityRun();

        $row = entityFirst(DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('result', 'waiting_approval'));
        expect($row)->not->toBeNull()->and($row->object_level)->toBe('ad');

        $proposal = json_decode((string) DB::table('approval_requests')->where('id', $row->approval_id)->value('proposal'), true);
        expect($proposal['level'])->toBe('ad')
            ->and($proposal['external_ad_id'])->toBe(DB::table('ads')->where('id', $row->entity_id)->value('external_id'))
            ->and(DB::table('approval_requests')->where('id', $row->approval_id)->value('subject_type'))->toBe('ad');

        /** @var list<array<string, mixed>> $entries */
        $entries = test()->actingAs($w->scenario->owner)->getJson('/api/automation/history')->assertOk()->json('data');
        $history = array_values(array_filter($entries, static fn (array $e): bool => $e['approval_id'] === $row->approval_id))[0];
        expect($history['object']['level'])->toBe('ad')
            ->and($history['object']['label'])->toBe(DB::table('ads')->where('id', $row->entity_id)->value('name'))
            ->and($history['object']['parent'])->toContain(' › ');
    });

    it('records a change the platform does not document at that level as unsupported, and attempts nothing', function (): void {
        $w = entityWorld();
        // X pauses campaigns and line items; it documents no way to pause a promoted tweet. Saved over every platform,
        // the rule is checked entity by entity.
        $rule = entityRule($w, ['object_level' => 'ad', 'action' => 'propose_pause']);
        $proposals = DB::table('approval_requests')->count();

        entityRun();

        $x = DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('provider', 'x')->get();
        expect($x)->not->toBeEmpty()
            ->and($x->pluck('result')->unique()->all())->toBe(['unsupported'])
            ->and($x->pluck('reason_code')->unique()->all())->toBe(['PROVIDER_WRITE_NOT_DOCUMENTED'])
            // Only Meta's ads were proposed.
            ->and(DB::table('approval_requests')->count() - $proposals)->toBe(DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('result', 'waiting_approval')->count());
    });

    it('proposes an ad-set budget only where the ad set holds a daily budget, inside the project\'s limit', function (): void {
        $w = entityWorld();
        $group = entityFirst(DB::table('ad_groups')->where('provider', 'meta')->where('status', 'active'));
        DB::table('ad_groups')->where('id', '!=', $group->id)->update(['budget_mode' => 'lifetime']);
        DB::table('ad_groups')->where('id', $group->id)->update(['budget_mode' => 'daily', 'budget_minor' => 10_000]);

        $rule = entityRule($w, ['object_level' => 'ad_group', 'providers' => ['meta'], 'action' => 'propose_budget_cut', 'action_pct' => 10]);
        entityRun();

        $mine = entityFirst(DB::table('automation_rule_executions')->where(['rule_id' => $rule['id'], 'entity_id' => $group->id]));
        $proposal = json_decode((string) DB::table('approval_requests')->where('id', $mine->approval_id)->value('proposal'), true);

        expect($mine->result)->toBe('waiting_approval')
            ->and($proposal['daily_budget_minor'])->toBe(9_000)
            ->and($proposal['level'])->toBe('ad_group')
            ->and(DB::table('approval_requests')->where('id', $mine->approval_id)->value('kind'))->toBe('spend_change');

        // An ad set whose budget is not a daily one has nothing to change.
        expect(DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('entity_id', '!=', $group->id)->distinct()->pluck('result')->all())->toBe(['unsupported']);
    });

    it('keeps proposals apart by level: an ad set\'s pending pause does not block its campaign\'s', function (): void {
        $w = entityWorld();
        entityRule($w, ['object_level' => 'ad_group', 'providers' => ['meta'], 'action' => 'propose_pause']);
        entityRun();

        $campaignId = (string) DB::table('ad_groups')->where('id', DB::table('automation_rule_executions')->where('result', 'waiting_approval')->value('entity_id'))->value('campaign_id');

        test()->actingAs($w->scenario->owner)
            ->postJson("/api/campaigns/{$campaignId}/status-request", ['action' => 'pause', 'reason' => 'The whole campaign is over target.'])
            ->assertCreated();
    });

    it('counts what a dry run matched at its level and above, and writes nothing', function (): void {
        $w = entityWorld();
        $before = [DB::table('approval_requests')->count(), DB::table('automation_rule_executions')->count(), DB::table('automation_rules')->count()];

        $dry = entityDryRun($w, ['object_level' => 'ad', 'providers' => ['meta'], 'action' => 'propose_pause']);

        expect($dry['level'])->toBe('ad')
            ->and($dry['matched_by_level']['ad'])->toBe($dry['matched'])
            ->and($dry['matched_by_level']['ad_group'])->toBeGreaterThan(0)->toBeLessThanOrEqual($dry['matched'])
            ->and($dry['matched_by_level']['campaign'])->toBeGreaterThan(0)->toBeLessThanOrEqual($dry['matched_by_level']['ad_group'])
            ->and([DB::table('approval_requests')->count(), DB::table('automation_rule_executions')->count(), DB::table('automation_rules')->count()])->toBe($before);
    });

    it('refuses what a level does not have, in words', function (): void {
        $w = entityWorld();

        // An ad has no budget, and no daily budget to measure against.
        expect(entityRule($w, ['object_level' => 'ad', 'action' => 'propose_budget_cut', 'action_pct' => 10], 422))->toBe([]);
        entityRule($w, ['object_level' => 'ad', 'metric' => 'budget_used', 'threshold' => 90], 422);
        // A week-on-week cost judgement exists for a campaign only.
        entityRule($w, ['object_level' => 'ad_group', 'metric' => 'cost_change', 'threshold' => 20], 422);
        // A campaign rule cannot be scoped to ad groups.
        $group = (string) DB::table('ad_groups')->value('id');
        entityRule($w, ['object_level' => 'campaign', 'ad_group_ids' => [$group]], 422);
        // An ad group nobody can see is not found.
        entityRule($w, ['object_level' => 'ad_group', 'ad_group_ids' => [(string) Str::uuid()]], 404);

        expect(DB::table('automation_rules')->count())->toBe(0);
    });

    it('stays within its scope of ad groups', function (): void {
        $w = entityWorld();
        $group = entityFirst(DB::table('ad_groups')->where('provider', 'meta')->where('status', 'active'));

        $dry = entityDryRun($w, ['object_level' => 'ad', 'ad_group_ids' => [$group->id], 'action' => 'alert']);

        expect($dry['objects'])->not->toBeEmpty()
            ->and(array_unique(array_column($dry['objects'], 'ad_group_id')))->toBe([$group->id]);
    });
});
