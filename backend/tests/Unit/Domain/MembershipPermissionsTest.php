<?php

declare(strict_types=1);

use App\Domain\Access\Permission;
use App\Domain\Access\PermissionSet;
use App\Domain\Access\RoleId;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\Membership;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;

function membership(MembershipStatus $status): Membership
{
    return new Membership(
        userId: new UserId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90'),
        workspaceId: new WorkspaceId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c91'),
        roleId: new RoleId('0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c92'),
        roleSlug: SystemRole::Admin->value,
        status: $status,
        permissions: new PermissionSet(SystemRole::Admin->permissions()),
    );
}

it('gives an active member their role permissions', function (): void {
    expect(membership(MembershipStatus::Active)->effectivePermissions()->has(Permission::ClientsCreate))->toBeTrue();
});

it('strips every permission from a suspended member', function (): void {
    // The role says what they would have, not what they have.
    expect(membership(MembershipStatus::Suspended)->effectivePermissions()->isEmpty())->toBeTrue();
});

it('strips every permission from an invited member who has not joined', function (): void {
    expect(membership(MembershipStatus::Invited)->effectivePermissions()->isEmpty())->toBeTrue();
});
