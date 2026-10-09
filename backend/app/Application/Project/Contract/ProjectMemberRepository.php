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

    /**
     * Active members of the workspace who are **not** already on this project.
     *
     * The project module's own question, deliberately not `OwnerDirectory`'s. That one answers "who can see this
     * thing already" and is used to decide who may own a piece of work; this answers "who could be given access",
     * which is almost its inverse — the people worth offering are the ones who cannot see the project yet.
     *
     * Every role in this product holds `projects.view_assigned`, so an assignment means something for any of
     * them, including a client viewer: putting a client on their own project is how the portal comes to show
     * them anything at all.
     *
     * @return list<array{user_id: string, name: string, role_slug: string, role_name: string}>
     */
    public function assignableTo(WorkspaceId $workspaceId, ProjectId $projectId): array;

    public function findWorkspaceRoleId(WorkspaceId $workspaceId, string $roleSlug): ?RoleId;
}
