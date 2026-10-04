<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Symfony\Component\HttpFoundation\Response;

it('signs a member in and returns their workspaces and permissions', function (): void {
    $scenario = workspaceScenario('Smart Code');
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);
    $buyer->forceFill(['password' => 'correct-horse-battery'])->save();

    $response = $this->postJson('/api/auth/login', [
        'email' => $buyer->getAttribute('email'),
        'password' => 'correct-horse-battery',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', $buyer->getAttribute('email'))
        ->assertJsonPath('data.active_workspace.name', 'Smart Code')
        ->assertJsonPath('data.active_workspace.role', SystemRole::MediaBuyer->value);

    $permissions = $response->json('data.permissions');

    expect($permissions)->toContain('projects.view_assigned');
    expect($permissions)->not->toContain('clients.create');
});

it('rejects a wrong password without revealing whether the account exists', function (): void {
    $scenario = workspaceScenario();
    $user = $scenario->owner;
    $user->forceFill(['password' => 'the-real-password'])->save();

    $wrongPassword = $this->postJson('/api/auth/login', [
        'email' => $user->getAttribute('email'),
        'password' => 'not-the-password',
    ]);

    $unknownAccount = $this->postJson('/api/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ]);

    $wrongPassword->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    $unknownAccount->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    // Identical responses: the login form must not be an enumeration oracle.
    expect($wrongPassword->json('message'))->toBe($unknownAccount->json('message'));
});

it('records failed sign-in attempts', function (): void {
    $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'guessing']);

    $entry = AuditLog::query()->where('action', AuditAction::UserLoginFailed->value)->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->getAttribute('context'))->toMatchArray(['email' => 'nobody@example.com']);
});

it('records a successful sign-in against the workspace', function (): void {
    $scenario = workspaceScenario();
    $scenario->owner->forceFill(['password' => 'the-real-password'])->save();

    $this->postJson('/api/auth/login', [
        'email' => $scenario->owner->getAttribute('email'),
        'password' => 'the-real-password',
    ])->assertOk();

    expect(AuditLog::query()
        ->where('action', AuditAction::UserLoggedIn->value)
        ->where('workspace_id', $scenario->workspace->getKey())
        ->where('actor_id', $scenario->owner->getKey())
        ->exists())->toBeTrue();
});

it('refuses a deactivated account and says so', function (): void {
    $scenario = workspaceScenario();
    $scenario->owner->forceFill(['password' => 'the-real-password', 'is_active' => false])->save();

    $response = $this->postJson('/api/auth/login', [
        'email' => $scenario->owner->getAttribute('email'),
        'password' => 'the-real-password',
    ]);

    $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect($response->json('message'))->toContain('deactivated');
    $this->assertGuest();
});

it('refuses a user who belongs to no workspace, and does not leave them signed in', function (): void {
    $user = User::factory()->create(['password' => 'the-real-password']);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->getAttribute('email'),
        'password' => 'the-real-password',
    ]);

    $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect($response->json('message'))->toContain('not a member of any workspace');
    $this->assertGuest();
});

it('does not authenticate a suspended member', function (): void {
    $scenario = workspaceScenario();
    $suspended = $scenario->addMember(SystemRole::Creative, MembershipStatus::Suspended);
    $suspended->forceFill(['password' => 'the-real-password'])->save();

    // They authenticate, but hold no active membership, so there is no
    // workspace to land in and the session is discarded.
    $this->postJson('/api/auth/login', [
        'email' => $suspended->getAttribute('email'),
        'password' => 'the-real-password',
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    $this->assertGuest();
});

it('requires authentication for /api/auth/me', function (): void {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

it('returns the current session', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', (string) $scenario->owner->getKey())
        ->assertJsonPath('data.active_workspace.id', (string) $scenario->workspace->getKey());
});

it('signs out and records it', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    expect(AuditLog::query()->where('action', AuditAction::UserLoggedOut->value)->exists())->toBeTrue();
});

it('rate limits repeated sign-in attempts', function (): void {
    $payload = ['email' => 'target@example.com', 'password' => 'guessing'];

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/auth/login', $payload)->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    $this->postJson('/api/auth/login', $payload)->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
});
