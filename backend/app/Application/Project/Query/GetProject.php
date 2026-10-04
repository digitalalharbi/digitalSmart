<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;

final readonly class GetProject
{
    public function __construct(
        private ProjectReadModel $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(ProjectId $projectId): ProjectView
    {
        $this->guard->ensureAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned);

        $visible = $this->guard->allows(Permission::ProjectsViewAll)
            ? null
            : $this->actor->assignedProjectIds();

        $project = $this->projects->find(
            $this->actor->workspaceId(),
            $this->actor->userId(),
            $visible,
            $projectId,
        );

        // Unassigned is indistinguishable from non-existent, as with workspaces.
        return $project ?? throw ResourceNotFound::of('Project');
    }
}
