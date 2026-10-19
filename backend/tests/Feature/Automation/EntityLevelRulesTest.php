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
        $onlyAds = test()->actingAs($w->scenario->owner)->getJson('/api/automation/history?level=ad')->assertOk()->json('data');
        expect(array_unique(array_column(array_column($onlyAds, 'object'), 'level')))->toBe(['ad']);

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

describe('the campaign hierarchy', function (): void {
    it('opens a campaign into ad groups, ads and the creatives they run, with what can be asked at each level', function (): void {
        $w = entityWorld();
        $campaign = (string) DB::table('ads')->where('provider', 'meta')->whereNotNull('creative_id')->value('campaign_id');

        $data = test()->actingAs($w->scenario->owner)->getJson("/api/campaigns/{$campaign}/hierarchy")->assertOk()->json('data');

        expect($data['groups'])->not->toBeEmpty()
            ->and($data['writes']['ad_group']['pause']['support'])->toBe('documented')
            ->and($data['writes']['ad']['pause']['support'])->toBe('documented')
            // An ad has no budget to ask about.
            ->and($data['writes']['ad'])->not->toHaveKey('budget');

        // Each creative once, linked from the ads that run it — the platform's own creative, never a copy.
        expect($data['creatives'])->not->toBeEmpty();
        $ads = array_merge(...array_map(static fn (array $g): array => $g['ads'], $data['groups']));
        foreach ($data['creatives'] as $creative) {
            $using = array_filter($ads, static fn (array $ad): bool => ($ad['creative']['id'] ?? null) === $creative['id']);
            expect($creative['ads'])->toBe(count($using));
        }
        expect(count(array_unique(array_column($data['creatives'], 'id'))))->toBe(count($data['creatives']));
    });

    it('says, in the platform\'s words, what cannot be asked of an X promoted tweet', function (): void {
        $w = entityWorld();
        $campaign = (string) DB::table('ads')->where('provider', 'x')->value('campaign_id');

        $writes = test()->actingAs($w->scenario->owner)->getJson("/api/campaigns/{$campaign}/hierarchy")->assertOk()->json('data.writes');

        expect($writes['ad_group']['pause']['support'])->toBe('documented')
            ->and($writes['ad']['pause']['support'])->toBe('not_offered')
            ->and($writes['ad']['pause']['because'])->toContain('promoted Tweet');
    });

    it('lets a person ask to pause one ad, and refuses what makes no sense at that level', function (): void {
        $w = entityWorld();
        $ad = entityFirst(DB::table('ads')->where(['provider' => 'meta', 'status' => 'active']));
        $tweet = (string) DB::table('ads')->where('provider', 'x')->value('id');

        $request = test()->actingAs($w->scenario->owner)
            ->postJson("/api/campaigns/entities/ad/{$ad->id}/status-request", ['action' => 'pause', 'reason' => 'This ad is tired.'])
            ->assertCreated()->json('data');

        expect($request['proposal']['level'])->toBe('ad')
            ->and($request['proposal']['external_ad_id'])->toBe($ad->external_id)
            ->and($request['origin'])->toBe('person');

        test()->actingAs($w->scenario->owner)
            ->postJson("/api/campaigns/entities/ad/{$ad->id}/status-request", ['action' => 'resume', 'reason' => 'Turn it on.'])
            ->assertUnprocessable()->assertJsonPath('code', 'wrong_state');

        test()->actingAs($w->scenario->owner)
            ->postJson("/api/campaigns/entities/ad/{$tweet}/status-request", ['action' => 'pause', 'reason' => 'Stop it.'])
            ->assertUnprocessable()->assertJsonPath('code', 'provider_not_documented');
    });

    it('acts on many ad groups at once — each through its own use case, each refusal named', function (): void {
        $w = entityWorld();
        $groups = DB::table('ad_groups')->where('provider', 'meta')->pluck('id')->map(static fn ($id): string => (string) $id)->all();
        $x = (string) DB::table('ad_groups')->where('provider', 'x')->value('id');

        $tasks = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/entities/bulk', [
            'level' => 'ad_group', 'ids' => $groups, 'action' => 'create_task', 'reason' => 'Review the audience',
        ])->assertOk()->json('data');
        expect($tasks['done'])->toBe(count($groups))
            ->and(DB::table('tasks')->where('subject_type', 'ad_group')->count())->toBe(count($groups));

        $pause = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/entities/bulk', [
            'level' => 'ad_group', 'ids' => [...$groups, $x, (string) Str::uuid()], 'action' => 'request_pause', 'reason' => 'Over target all week',
        ])->assertOk()->json('data');
        $codes = array_column($pause['results'], 'code', 'id');
        expect($codes[array_key_last($codes)])->toBe('not_found')
            ->and($pause['done'])->toBeGreaterThan(0);

        $rule = entityRule($w, ['object_level' => 'ad_group', 'action' => 'alert']);
        test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/entities/bulk', [
            'level' => 'ad_group', 'ids' => array_slice($groups, 0, 2), 'action' => 'add_to_rule', 'rule_id' => $rule['id'],
        ])->assertOk();
        expect(json_decode((string) DB::table('automation_rules')->where('id', $rule['id'])->value('ad_group_ids'), true))->toEqualCanonicalizing(array_slice($groups, 0, 2));

        // A rule judges one level: ads cannot join an ad-group rule.
        $ad = (string) DB::table('ads')->where('provider', 'meta')->value('id');
        $mismatch = test()->actingAs($w->scenario->owner)->postJson('/api/campaigns/entities/bulk', [
            'level' => 'ad', 'ids' => [$ad], 'action' => 'add_to_rule', 'rule_id' => $rule['id'],
        ])->assertOk()->json('data');
        expect($mismatch['results'][0]['code'])->toBe('level_mismatch');
    });

    it('tags ad groups and ads like campaigns', function (): void {
        $w = entityWorld();
        $tag = (string) test()->actingAs($w->scenario->owner)->postJson('/api/tags', ['name' => 'Ramadan test'])->assertCreated()->json('data.id');
        $group = (string) DB::table('ad_groups')->where('provider', 'meta')->value('id');
        $ad = (string) DB::table('ads')->where('provider', 'meta')->value('id');

        test()->actingAs($w->scenario->owner)->postJson("/api/tags/{$tag}/entities/ad_group", ['ids' => [$group]])->assertOk();
        test()->actingAs($w->scenario->owner)->putJson("/api/tags/{$tag}/entities/ad/{$ad}")->assertSuccessful();

        expect(DB::table('taggings')->where('tag_id', $tag)->pluck('entity_type')->sort()->values()->all())->toBe(['ad', 'ad_group']);
    });
});

describe('the same guarantees at every level', function (): void {
    it('judges nothing below an ad group\'s or an ad\'s minimum data, and says so', function (): void {
        $w = entityWorld();

        foreach (['ad_group', 'ad'] as $level) {
            $plain = entityDryRun($w, ['object_level' => $level, 'providers' => ['meta'], 'action' => 'alert']);
            $strict = entityDryRun($w, ['object_level' => $level, 'providers' => ['meta'], 'action' => 'alert', 'min_spend' => 100_000_000]);

            expect($plain['matched'])->toBeGreaterThan(0)
                ->and($strict['matched'])->toBe(0)
                ->and($strict['insufficient'])->toBe($plain['looked_at']);
        }
    });

    it('waits out its cooldown per entity, recording it once', function (): void {
        $w = entityWorld();

        foreach (['ad_group', 'ad'] as $level) {
            $rule = entityRule($w, ['object_level' => $level, 'providers' => ['meta'], 'action' => 'propose_pause', 'cooldown_hours' => 24]);
            entityRun();

            $acted = DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('result', 'waiting_approval')->pluck('entity_id')->all();
            expect($acted)->not->toBeEmpty();

            DB::table('automation_rule_streaks')->where('rule_id', $rule['id'])->update(['acted_at' => null, 'first_seen_on' => now()->subDays(3)->toDateString()]);
            $proposals = DB::table('approval_requests')->count();
            entityRun();
            entityRun();

            $cooling = DB::table('automation_rule_executions')->where('rule_id', $rule['id'])->where('result', 'cooldown')->whereIn('entity_id', $acted)->get();
            expect($cooling)->toHaveCount(count($acted))
                ->and($cooling->pluck('reason_code')->unique()->all())->toBe(['COOLDOWN'])
                ->and(DB::table('approval_requests')->count())->toBe($proposals);
        }
    });

    it('stands down when another rule moved the same ad group the other way', function (): void {
        $w = entityWorld();
        $group = entityFirst(DB::table('ad_groups')->where(['provider' => 'meta', 'status' => 'active']));
        DB::table('ad_groups')->where('id', $group->id)->update(['budget_mode' => 'daily', 'budget_minor' => 10_000]);

        $raise = entityRule($w, ['object_level' => 'ad_group', 'ad_group_ids' => [$group->id], 'action' => 'propose_budget_increase', 'action_pct' => 10]);
        entityRun();
        expect(DB::table('automation_rule_executions')->where(['rule_id' => $raise['id'], 'entity_id' => $group->id])->value('result'))->toBe('waiting_approval');

        $cut = entityRule($w, ['object_level' => 'ad_group', 'ad_group_ids' => [$group->id], 'action' => 'propose_budget_cut', 'action_pct' => 10]);
        entityRun();

        $trim = entityFirst(DB::table('automation_rule_executions')->where(['rule_id' => $cut['id'], 'entity_id' => $group->id]));
        expect($trim->result)->toBe('conflict')->and($trim->reason_code)->toBe('CONFLICT');

        // A rule on the same ad group's campaign is a different entity: it is not blocked by the ad group's proposal.
        $campaign = entityRule($w, ['object_level' => 'campaign', 'campaign_ids' => [$group->campaign_id], 'action' => 'propose_pause']);
        entityRun();
        expect(DB::table('automation_rule_executions')->where('rule_id', $campaign['id'])->value('result'))->not->toBe('conflict');
    });
});
