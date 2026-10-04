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

final readonly class RemoveProjectMember
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, UserId $userId): void
    {
        $this->guard->ensure(Permission::ProjectsManageMembers);

        $workspaceId = $this->actor->workspaceId();
        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        if (! $this->members->remove($projectId, $userId)) {
            throw ResourceNotFound::of('Project member');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectMemberRemoved,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Removed a member from "%s".', $project->name()),
            changes: ['user_id' => $userId->value],
        ));
    }
}
