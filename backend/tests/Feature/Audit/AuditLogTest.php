<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\Client;

it('requires the audit permission', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);

    $this->actingAs($buyer)->getJson('/api/audit-logs')->assertForbidden();
});

it('returns the workspace trail newest first', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'First client'])->assertCreated();
    $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'Second client'])->assertCreated();

    $response = $this->actingAs($scenario->owner)->getJson('/api/audit-logs');

    $response->assertOk();
    expect($response->json('data.0.summary'))->toContain('Second client');
});

it('never shows another workspace’s trail', function (): void {
    $mine = workspaceScenario('Mine');
    $theirs = workspaceScenario('Theirs');

    $this->actingAs($theirs->owner)->postJson('/api/clients', ['name' => 'Their secret client'])->assertCreated();

    $response = $this->actingAs($mine->owner)->getJson('/api/audit-logs');

    $summaries = implode(' ', array_column($response->json('data'), 'summary'));
    expect($summaries)->not->toContain('Their secret client');
});

it('attributes each entry to the person who acted', function (): void {
    $scenario = workspaceScenario();
    $manager = $scenario->addMember(SystemRole::ProjectManager);

    $this->actingAs($manager)->postJson('/api/clients', ['name' => 'Managed client'])->assertCreated();

    $response = $this->actingAs($scenario->owner)->getJson('/api/audit-logs');

    expect($response->json('data.0.actor.id'))->toBe((string) $manager->getKey())
        ->and($response->json('data.0.actor.name'))->toBe($manager->getAttribute('name'));
});

it('filters to security events', function (): void {
    $scenario = workspaceScenario();
    Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'Routine change'])->assertCreated();

    $response = $this->actingAs($scenario->owner)->getJson('/api/audit-logs?security_only=1');

    $actions = array_column($response->json('data'), 'action');
    expect($actions)->not->toContain(AuditAction::ClientCreated->value);
});

it('filters by action', function (): void {
    $scenario = workspaceScenario();
    $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'A client'])->assertCreated();

    $response = $this->actingAs($scenario->owner)
        ->getJson('/api/audit-logs?action[]='.AuditAction::ClientCreated->value);

    $response->assertOk();
    expect(array_unique(array_column($response->json('data'), 'action')))
        ->toBe([AuditAction::ClientCreated->value]);
});

it('marks whether an entry is a security event', function (): void {
    $scenario = workspaceScenario();
    $scenario->owner->forceFill(['password' => 'the-real-password'])->save();

    $this->postJson('/api/auth/login', [
        'email' => $scenario->owner->getAttribute('email'),
        'password' => 'the-real-password',
    ])->assertOk();

    $response = $this->actingAs($scenario->owner)->getJson('/api/audit-logs');

    expect($response->json('data.0.is_security_event'))->toBeTrue()
        ->and($response->json('data.0.action'))->toBe(AuditAction::UserLoggedIn->value);
});
