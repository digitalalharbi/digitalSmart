<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Symfony\Component\HttpFoundation\Response;

it('requires authentication', function (): void {
    $this->getJson('/api/members')->assertUnauthorized();
});

it('lists the people in the active workspace only', function (): void {
    $mine = workspaceScenario('Mine');
    $mine->addMember(SystemRole::MediaBuyer);
    $theirs = workspaceScenario('Theirs');
    $theirs->addMember(SystemRole::Creative);

    $response = $this->actingAs($mine->owner)->getJson('/api/members');

    // Owner + buyer, never the other workspace's people.
    $response->assertOk()->assertJsonPath('meta.total', 2);
});

it('hides the member directory from a client viewer', function (): void {
    $scenario = workspaceScenario();
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->getJson('/api/members')->assertForbidden();
});

it('changes a member role and records who did it', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/role", ['role' => SystemRole::ProjectManager->value])
        ->assertNoContent();

    expect(AuditLog::query()
        ->where('action', AuditAction::WorkspaceMemberRoleChanged->value)
        ->where('actor_id', $scenario->owner->getKey())
        ->exists())->toBeTrue();

    // The new role takes effect immediately.
    $this->actingAs($buyer)->getJson('/api/clients')->assertOk();
    $this->actingAs($buyer)->postJson('/api/clients', ['name' => 'Now allowed'])->assertCreated();
});

it('refuses to demote the last owner', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'last_owner_protected');
});

it('allows demoting an owner once another owner exists', function (): void {
    $scenario = workspaceScenario();
    $second = $scenario->addMember(SystemRole::Owner);

    $this->actingAs($second)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertNoContent();
});

it('suspends a member and strips their access immediately', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/status", ['status' => MembershipStatus::Suspended->value])
        ->assertNoContent();

    // Suspended: the role still says buyer, but it grants nothing.
    $this->actingAs($buyer)->getJson('/api/clients')->assertForbidden();
});

it('reinstates a suspended member', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer, MembershipStatus::Suspended);

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$buyer->getKey()}/status", ['status' => MembershipStatus::Active->value])
        ->assertNoContent();

    $this->actingAs($buyer)->getJson('/api/clients')->assertOk();
});

it('refuses to suspend the last owner', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->patchJson("/api/members/{$scenario->owner->getKey()}/status", ['status' => 'suspended'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'last_owner_protected');
});

it('removes a member but keeps their account and audit trail', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)
        ->deleteJson("/api/members/{$buyer->getKey()}")
        ->assertNoContent();

    expect(WorkspaceMember::query()->where('user_id', $buyer->getKey())->exists())->toBeFalse()
        ->and($buyer->fresh())->not->toBeNull();
});

it('refuses member administration to a project manager', function (): void {
    $scenario = workspaceScenario();
    $manager = $scenario->addMember(SystemRole::ProjectManager);
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($manager)
        ->patchJson("/api/members/{$buyer->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertForbidden();
});

it('reports a member of another workspace as not found', function (): void {
    $mine = workspaceScenario('Mine');
    $outsider = workspaceScenario('Theirs')->owner;

    $this->actingAs($mine->owner)
        ->patchJson("/api/members/{$outsider->getKey()}/role", ['role' => SystemRole::Creative->value])
        ->assertNotFound();
});

it('denies a user whose workspace membership has been removed entirely', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($scenario->owner)->deleteJson("/api/members/{$buyer->getKey()}")->assertNoContent();

    // Their session may still exist, but it now carries no workspace at all.
    $response = $this->actingAs($buyer)->getJson('/api/clients');

    $response->assertForbidden();
    expect($response->json('message'))->toContain('active membership of any workspace');
});
