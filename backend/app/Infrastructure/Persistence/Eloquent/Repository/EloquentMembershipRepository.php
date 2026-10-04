<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Workspace\Contract\MembershipRepository;
use App\Domain\Access\PermissionSet;
use App\Domain\Access\RoleId;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\Membership;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentMembershipRepository implements MembershipRepository
{
    public function find(WorkspaceId $workspaceId, UserId $userId): ?Membership
    {
        $row = DB::table('workspace_members')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->where('workspace_members.workspace_id', $workspaceId->value)
            ->where('workspace_members.user_id', $userId->value)
            ->first([
                'workspace_members.role_id', 'workspace_members.status', 'roles.slug as role_slug',
            ]);

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var list<string> $slugs */
        $slugs = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $row->role_id)
            ->pluck('permissions.slug')
            ->all();

        return new Membership(
            userId: $userId,
            workspaceId: $workspaceId,
            roleId: new RoleId((string) $row->role_id),
            roleSlug: (string) $row->role_slug,
            status: MembershipStatus::from((string) $row->status),
            permissions: PermissionSet::fromSlugs($slugs),
        );
    }

    public function changeRole(WorkspaceId $workspaceId, UserId $userId, RoleId $roleId): void
    {
        WorkspaceMember::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('user_id', $userId->value)
            ->update(['role_id' => $roleId->value]);
    }

    public function changeStatus(WorkspaceId $workspaceId, UserId $userId, MembershipStatus $status): void
    {
        WorkspaceMember::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('user_id', $userId->value)
            ->update(['status' => $status->value]);
    }

    public function remove(WorkspaceId $workspaceId, UserId $userId): bool
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('user_id', $userId->value)
            ->delete() > 0;
    }

    public function findRoleIdBySlug(WorkspaceId $workspaceId, string $slug): ?RoleId
    {
        $id = DB::table('roles')
            ->where('workspace_id', $workspaceId->value)
            ->where('slug', $slug)
            ->value('id');

        return is_string($id) ? new RoleId($id) : null;
    }

    public function countActiveWithRole(WorkspaceId $workspaceId, string $roleSlug): int
    {
        return DB::table('workspace_members')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->where('workspace_members.workspace_id', $workspaceId->value)
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->where('roles.slug', $roleSlug)
            ->count();
    }
}
