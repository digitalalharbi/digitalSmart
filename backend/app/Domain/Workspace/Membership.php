<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Access\PermissionSet;
use App\Domain\Access\RoleId;
use App\Domain\Identity\UserId;

/** One person's standing in one workspace: their role and whether it is live. */
final readonly class Membership
{
    public function __construct(
        public UserId $userId,
        public WorkspaceId $workspaceId,
        public RoleId $roleId,
        public string $roleSlug,
        public MembershipStatus $status,
        public PermissionSet $permissions,
    ) {}

    /**
     * Effective permissions.
     *
     * A suspended or merely invited member holds none, whatever their role says
     * — the role is what they would have, not what they have.
     */
    public function effectivePermissions(): PermissionSet
    {
        return $this->status->grantsAccess() ? $this->permissions : PermissionSet::empty();
    }
}
