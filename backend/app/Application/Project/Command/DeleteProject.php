<?php

declare(strict_types=1);

namespace App\Application\Project\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;

/** Soft-deletes a project; its campaigns, spend and reports are retained. */
final readonly class DeleteProject
{
    public function __construct(
        private ProjectRepository $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId): void
    {
        $this->guard->ensure(Permission::ProjectsDelete);
        $this->guard->ensureProjectVisible($projectId);

        $workspaceId = $this->actor->workspaceId();
        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        $this->projects->remove($project);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectDeleted,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Deleted project "%s".', $project->name()),
        ));
    }
}
