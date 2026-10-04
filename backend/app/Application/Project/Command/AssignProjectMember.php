<?php

declare(strict_types=1);

namespace App\Application\Project\Command;

use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;

/**
 * Assign someone to a project.
 *
 * This is the grant that makes a project visible to a buyer or creative, so it
 * is permission-gated, audited, and refuses anyone who is not already an active
 * member of the workspace.
 */
final readonly class AssignProjectMember
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, UserId $userId, string $roleSlug): void
    {
        $this->guard->ensure(Permission::ProjectsManageMembers);

        $workspaceId = $this->actor->workspaceId();

        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        // Assigning someone from outside the workspace would breach isolation.
        if (! $this->members->isWorkspaceMember($workspaceId, $userId)) {
            throw ResourceNotFound::of('User');
        }

        $roleId = $this->members->findWorkspaceRoleId($workspaceId, $roleSlug)
            ?? throw ResourceNotFound::of('Role');

        $this->members->assign($projectId, $userId, $roleId, $this->actor->userId());

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectMemberAssigned,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Assigned a member to "%s" as %s.', $project->name(), $roleSlug),
            changes: ['user_id' => $userId->value, 'role' => $roleSlug],
        ));
    }
}
