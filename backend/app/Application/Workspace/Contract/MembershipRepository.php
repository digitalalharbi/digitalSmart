<?php

declare(strict_types=1);

namespace App\Application\Workspace\Contract;

use App\Domain\Access\RoleId;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\Membership;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;

interface MembershipRepository
{
    public function find(WorkspaceId $workspaceId, UserId $userId): ?Membership;

    public function changeRole(WorkspaceId $workspaceId, UserId $userId, RoleId $roleId): void;

    public function changeStatus(WorkspaceId $workspaceId, UserId $userId, MembershipStatus $status): void;

    /** Revoke the membership. The row is kept; inviting the person again reactivates it. */
    public function remove(WorkspaceId $workspaceId, UserId $userId, ?UserId $by = null): bool;

    public function findRoleIdBySlug(WorkspaceId $workspaceId, string $slug): ?RoleId;

    /** How many active members hold a given role — used to protect the last owner. */
    public function countActiveWithRole(WorkspaceId $workspaceId, string $roleSlug): int;
}
