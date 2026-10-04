<?php

declare(strict_types=1);

namespace App\Application\Project\Command;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Project\ProjectId;
use App\Domain\Project\ProjectStatus;
use App\Domain\Project\Repository\ProjectRepository;

final readonly class ChangeProjectStatus
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, ProjectStatus $status): ProjectView
    {
        $this->guard->ensure(Permission::ProjectsUpdate);
        $this->guard->ensureProjectVisible($projectId);

        $workspaceId = $this->actor->workspaceId();
        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        $from = $project->status();
        $project->changeStatus($status);

        $this->projects->save($project);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectStatusChanged,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Project "%s" moved from %s to %s.', $project->name(), $from->value, $status->value),
            // Whether spend is permitted changes with status, so it is recorded.
            changes: ['from' => $from->value, 'to' => $status->value, 'allows_spend' => $status->allowsSpend()],
        ));

        return $this->readModel->find($workspaceId, $this->actor->userId(), null, $projectId)
            ?? throw ResourceNotFound::of('Project');
    }
}
