<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

/**
 * Rules watch campaigns and say so. Their one outcome is an item in the attention queue; nothing here changes a
 * campaign, which is also what the last test holds the product to.
 */
function ruleWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([
        Provider::Meta->value => 'act_sandbox_1001',
        Provider::GoogleAds->value => 'act_sandbox_2001',
    ]);
}

it('creates, lists, changes and deletes a rule, and says which metrics it understands', function (): void {
    $w = ruleWorld();
    $owner = $w->scenario->owner;

    $created = test()->actingAs($owner)->postJson('/api/automation/rules', [
        'name' => 'Cost per result too high',
        'metric' => 'cost_per_result',
        'threshold' => 40,
        'window_days' => 14,
    ])->assertCreated()->json('data');

    expect($created['operator'])->toBe('above')
        ->and($created['threshold'])->toEqual(40)
        ->and($created['is_active'])->toBeTrue();

    $list = test()->actingAs($owner)->getJson('/api/automation/rules')->assertOk()->json();
    expect($list['data'])->toHaveCount(1)
        ->and(array_column($list['meta']['metrics'], 'metric'))->toContain('roas', 'no_results');

    test()->actingAs($owner)->putJson("/api/automation/rules/{$created['id']}", [
        'name' => 'Cost per result too high',
        'metric' => 'cost_per_result',
        'threshold' => 55,
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.threshold', 55)->assertJsonPath('data.is_active', false);

    test()->actingAs($owner)->deleteJson("/api/automation/rules/{$created['id']}")->assertNoContent();
    expect(test()->actingAs($owner)->getJson('/api/automation/rules')->json('data'))->toBe([]);
});

it('refuses a rule that has nothing to compare against, and a name used twice', function (): void {
    $owner = ruleWorld()->scenario->owner;

    test()->actingAs($owner)->postJson('/api/automation/rules', ['name' => 'No limit', 'metric' => 'roas'])
        ->assertStatus(422);

    $body = ['name' => 'Silent spend', 'metric' => 'no_results'];
    test()->actingAs($owner)->postJson('/api/automation/rules', $body)->assertCreated();
    test()->actingAs($owner)->postJson('/api/automation/rules', $body)->assertStatus(422);
});

it('raises the campaigns that break a rule in the attention queue, and stops when the rule is off', function (): void {
    $w = ruleWorld();
    $owner = $w->scenario->owner;

    // A limit no campaign can satisfy: every active campaign with a cost per result is in breach.
    $rule = test()->actingAs($owner)->postJson('/api/automation/rules', [
        'name' => 'Any cost at all',
        'metric' => 'cost_per_result',
        'threshold' => 0.0001,
        'severity' => 'critical',
    ])->assertCreated()->json('data');

    $queue = test()->actingAs($owner)->getJson('/api/attention?stream[]=automation')->assertOk()->json();
    $messages = array_column($queue['data'], 'message');

    expect($queue['data'])->not->toBeEmpty()
        ->and($messages[0])->toContain('Any cost at all')
        ->and(array_unique(array_column($queue['data'], 'severity')))->toBe(['critical']);

    test()->actingAs($owner)->putJson("/api/automation/rules/{$rule['id']}", [
        'name' => 'Any cost at all',
        'metric' => 'cost_per_result',
        'threshold' => 0.0001,
        'is_active' => false,
    ])->assertOk();

    expect(test()->actingAs($owner)->getJson('/api/attention?stream[]=automation')->json('data'))->toBe([]);
});

it('lets a role that can read performance see rules, but only one that manages budgets write them', function (): void {
    $w = ruleWorld();
    $owner = $w->scenario->owner;
    $manager = $w->scenario->addMember(SystemRole::ProjectManager);
    $viewer = $w->scenario->addMember(SystemRole::ClientViewer);

    test()->actingAs($owner)->postJson('/api/automation/rules', ['name' => 'Watch', 'metric' => 'no_results'])->assertCreated();

    test()->actingAs($viewer)->getJson('/api/automation/rules')->assertForbidden();
    test()->actingAs($viewer)->postJson('/api/automation/rules', ['name' => 'Nope', 'metric' => 'no_results'])->assertForbidden();
    test()->actingAs($manager)->getJson('/api/automation/rules')->assertOk();
});
