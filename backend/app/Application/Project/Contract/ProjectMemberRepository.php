<?php

declare(strict_types=1);

namespace App\Application\Project\Contract;

use App\Domain\Access\RoleId;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

/** Assignments of people to projects. */
interface ProjectMemberRepository
{
    /** True only if the user is an active member of the workspace. */
    public function isWorkspaceMember(WorkspaceId $workspaceId, UserId $userId): bool;

    public function assign(ProjectId $projectId, UserId $userId, RoleId $roleId, UserId $addedBy): void;

    public function remove(ProjectId $projectId, UserId $userId): bool;

    /** @return list<array{user_id: string, name: string, email: string, role_slug: string, role_name: string}> */
    public function listFor(ProjectId $projectId): array;

    public function findWorkspaceRoleId(WorkspaceId $workspaceId, string $roleSlug): ?RoleId;
}
