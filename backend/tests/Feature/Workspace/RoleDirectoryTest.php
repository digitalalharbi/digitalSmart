<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;

it('requires authentication', function (): void {
    $this->getJson('/api/roles')->assertUnauthorized();
});

it('shows each role with what it holds and how many people hold it here', function (): void {
    $scenario = workspaceScenario();
    $scenario->addMember(SystemRole::MediaBuyer);
    $scenario->addMember(SystemRole::MediaBuyer);

    $data = $this->actingAs($scenario->owner)->getJson('/api/roles')->assertOk()->json('data');
    $roles = array_column($data['roles'], null, 'slug');

    expect($roles)->toHaveKey('media-buyer')
        ->and($roles['media-buyer']['members'])->toBe(2)
        ->and($roles['media-buyer']['permissions'])->not->toContain('workspaces.update')
        ->and($roles['owner']['permissions'])->toContain('users.view')
        // Every permission a role holds is one the catalogue explains.
        ->and(array_diff($roles['owner']['permissions'], array_column($data['permissions'], 'slug')))->toBe([]);
});

it('keeps the role directory from a client viewer', function (): void {
    $scenario = workspaceScenario();
    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    $this->actingAs($viewer)->getJson('/api/roles')->assertForbidden();
});
