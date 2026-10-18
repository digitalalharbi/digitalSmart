<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use Tests\Support\SyncedWorkspace;

function rulesWorld(): SyncedWorkspace
{
    return SyncedWorkspace::with([Provider::Meta->value => 'act_sandbox_1001']);
}

it('resolves a project’s rules from its own values, then its client’s, then the agency default — and says which', function (): void {
    $w = rulesWorld();
    $owner = $w->scenario->owner;
    $project = $w->project(Provider::Meta);
    $projectId = (string) $project->getKey();
    $clientId = (string) $project->getAttribute('client_id');

    test()->actingAs($owner)->putJson('/api/operating-rules', [
        'client_id' => $clientId,
        'target_cpa_minor' => 9000,
        'max_budget_change_pct' => 30,
        'revenue_authority' => 'store',
    ])->assertNoContent();

    test()->actingAs($owner)->putJson('/api/operating-rules', [
        'project_id' => $projectId,
        'target_cpa_minor' => 7500,
    ])->assertNoContent();

    $rules = test()->actingAs($owner)->getJson("/api/operating-rules?project_id={$projectId}")->assertOk()->json('data.effective');

    expect($rules['target_cpa_minor'])->toBe(7500)
        ->and($rules['sources']['target_cpa_minor'])->toBe('project')
        ->and($rules['max_budget_change_pct'])->toBe(30)
        ->and($rules['sources']['max_budget_change_pct'])->toBe('client')
        ->and($rules['revenue_authority'])->toBe('store')
        // Nothing set a ROAS target, and the product does not invent one.
        ->and($rules['target_roas'])->toBeNull()
        ->and($rules['sources']['target_roas'])->toBe('agency_default');
});

it('falls back to the agency default change limit when nobody set one', function (): void {
    $w = rulesWorld();
    $projectId = (string) $w->project(Provider::Meta)->getKey();

    $rules = test()->actingAs($w->scenario->owner)->getJson("/api/operating-rules?project_id={$projectId}")->json('data.effective');

    expect($rules['max_budget_change_pct'])->toBe(20)->and($rules['sources']['max_budget_change_pct'])->toBe('agency_default');
});

it('refuses nonsense limits and a missing scope', function (): void {
    $w = rulesWorld();
    $owner = $w->scenario->owner;
    $clientId = (string) $w->project(Provider::Meta)->getAttribute('client_id');

    test()->actingAs($owner)->putJson('/api/operating-rules', ['client_id' => $clientId, 'max_budget_change_pct' => 500])->assertStatus(422);
    test()->actingAs($owner)->putJson('/api/operating-rules', ['target_roas' => 3])->assertStatus(422);
});

it('lets those who read performance read the rules, only those who manage budgets change them, and nobody else in', function (): void {
    $w = rulesWorld();
    $projectId = (string) $w->project(Provider::Meta)->getKey();
    $manager = $w->scenario->addMember(SystemRole::ProjectManager);
    $creative = $w->scenario->addMember(SystemRole::Creative);
    $client = $w->scenario->addMember(SystemRole::ClientViewer);

    test()->actingAs($manager)->getJson("/api/operating-rules?project_id={$projectId}")->assertOk();
    test()->actingAs($creative)->getJson("/api/operating-rules?project_id={$projectId}")->assertForbidden();
    test()->actingAs($client)->getJson("/api/operating-rules?project_id={$projectId}")->assertForbidden();
    test()->actingAs($client)->putJson('/api/operating-rules', ['project_id' => $projectId, 'target_roas' => 3])->assertForbidden();
});

it('treats another workspace’s project as not found rather than as empty', function (): void {
    $w = rulesWorld();
    $other = rulesWorld();
    $foreign = (string) $other->project(Provider::Meta)->getKey();

    test()->actingAs($w->scenario->owner)->getJson("/api/operating-rules?project_id={$foreign}")->assertNotFound();
    test()->actingAs($w->scenario->owner)->putJson('/api/operating-rules', ['project_id' => $foreign, 'target_roas' => 3])->assertNotFound();
});
