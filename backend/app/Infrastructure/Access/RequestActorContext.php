<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Access\PermissionSet;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves the acting user, their workspace and their effective rights.
 *
 * Bound per request and memoised: permissions are read once, not on every
 * guard check. The workspace is set by middleware from the authenticated
 * session, never from a client-supplied parameter.
 */
final class RequestActorContext implements ActorContext, ActorContextBinder
{
    private ?PermissionSet $permissions = null;

    /** @var list<string>|null */
    private ?array $assignedProjectIds = null;

    public function __construct(
        private ?UserId $userId = null,
        private ?WorkspaceId $workspaceId = null,
    ) {}

    public function bind(UserId $userId, WorkspaceId $workspaceId): void
    {
        $this->userId = $userId;
        $this->workspaceId = $workspaceId;
        $this->permissions = null;
        $this->assignedProjectIds = null;
    }

    public function isBound(): bool
    {
        return $this->userId instanceof UserId && $this->workspaceId instanceof WorkspaceId;
    }

    public function userId(): UserId
    {
        return $this->userId ?? throw new RuntimeException('No acting user is bound to this request.');
    }

    public function workspaceId(): WorkspaceId
    {
        return $this->workspaceId ?? throw new RuntimeException('No workspace is bound to this request.');
    }

    public function permissions(): PermissionSet
    {
        return $this->permissions ??= $this->resolvePermissions();
    }

    /** @return list<string> */
    public function assignedProjectIds(): array
    {
        return $this->assignedProjectIds ??= $this->resolveAssignedProjects();
    }

    public function isAssignedTo(ProjectId $projectId): bool
    {
        return in_array($projectId->value, $this->assignedProjectIds(), true);
    }

    private function resolvePermissions(): PermissionSet
    {
        /** @var list<string> $slugs */
        $slugs = DB::table('workspace_members')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('workspace_members.workspace_id', $this->workspaceId()->value)
            ->where('workspace_members.user_id', $this->userId()->value)
            // A suspended or invited member holds nothing, whatever their role grants.
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->pluck('permissions.slug')
            ->all();

        return PermissionSet::fromSlugs($slugs);
    }

    /** @return list<string> */
    private function resolveAssignedProjects(): array
    {
        /** @var list<string> $ids */
        $ids = DB::table('project_members')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->where('project_members.user_id', $this->userId()->value)
            ->where('projects.workspace_id', $this->workspaceId()->value)
            ->whereNull('projects.deleted_at')
            ->pluck('project_members.project_id')
            ->all();

        return $ids;
    }
}
