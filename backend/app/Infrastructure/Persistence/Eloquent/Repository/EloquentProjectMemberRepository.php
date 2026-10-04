<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Project\Contract\ProjectMemberRepository;
use App\Domain\Access\RoleId;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentProjectMemberRepository implements ProjectMemberRepository
{
    public function isWorkspaceMember(WorkspaceId $workspaceId, UserId $userId): bool
    {
        return DB::table('workspace_members')
            ->where('workspace_id', $workspaceId->value)
            ->where('user_id', $userId->value)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }

    public function assign(ProjectId $projectId, UserId $userId, RoleId $roleId, UserId $addedBy): void
    {
        // Idempotent: re-assigning updates the role rather than failing.
        ProjectMember::query()->updateOrCreate(
            ['project_id' => $projectId->value, 'user_id' => $userId->value],
            ['role_id' => $roleId->value, 'added_by' => $addedBy->value],
        );
    }

    public function remove(ProjectId $projectId, UserId $userId): bool
    {
        return ProjectMember::query()
            ->where('project_id', $projectId->value)
            ->where('user_id', $userId->value)
            ->delete() > 0;
    }

    /** @return list<array{user_id: string, name: string, email: string, role_slug: string, role_name: string}> */
    public function listFor(ProjectId $projectId): array
    {
        /** @var list<array{user_id: string, name: string, email: string, role_slug: string, role_name: string}> $members */
        $members = DB::table('project_members')
            ->join('users', 'users.id', '=', 'project_members.user_id')
            ->join('roles', 'roles.id', '=', 'project_members.role_id')
            ->where('project_members.project_id', $projectId->value)
            ->whereNull('users.deleted_at')
            ->orderBy('users.name')
            ->get([
                'project_members.user_id',
                'users.name',
                'users.email',
                'roles.slug as role_slug',
                'roles.name as role_name',
            ])
            ->map(static fn (stdClass $row): array => [
                'user_id' => (string) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'role_slug' => (string) $row->role_slug,
                'role_name' => (string) $row->role_name,
            ])
            ->values()
            ->all();

        return $members;
    }

    public function findWorkspaceRoleId(WorkspaceId $workspaceId, string $roleSlug): ?RoleId
    {
        $id = DB::table('roles')
            ->where('workspace_id', $workspaceId->value)
            ->where('slug', $roleSlug)
            ->value('id');

        return is_string($id) ? new RoleId($id) : null;
    }
}
