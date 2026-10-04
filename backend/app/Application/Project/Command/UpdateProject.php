<?php

declare(strict_types=1);

namespace App\Application\Project\Command;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectInput;
use App\Application\Project\DTO\ProjectView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;
use App\Domain\Shared\ValueObject\Slug;
use DateTimeImmutable;

final readonly class UpdateProject
{
    public function __construct(
        private ProjectRepository $projects,
        private ProjectReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, ProjectInput $input): ProjectView
    {
        $this->guard->ensure(Permission::ProjectsUpdate);
        // Updating requires being able to see it, which for most roles means
        // being assigned to it.
        $this->guard->ensureProjectVisible($projectId);

        $workspaceId = $this->actor->workspaceId();
        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        $before = ['name' => $project->name(), 'slug' => $project->slug()->value];

        $project->rename($input->name);
        $project->describe($input->description);
        $project->reschedule($this->toDate($input->startsOn), $this->toDate($input->endsOn));

        if ($input->slug !== null) {
            $project->changeSlug(new Slug($input->slug));
        }

        $this->projects->save($project);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectUpdated,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Updated project "%s".', $project->name()),
            changes: ['before' => $before, 'after' => ['name' => $project->name(), 'slug' => $project->slug()->value]],
        ));

        return $this->readModel->find($workspaceId, $this->actor->userId(), null, $projectId)
            ?? throw ResourceNotFound::of('Project');
    }

    private function toDate(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
