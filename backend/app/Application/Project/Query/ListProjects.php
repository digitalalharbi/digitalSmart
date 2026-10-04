<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectFilters;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Access\Permission;

final readonly class ListProjects
{
    public function __construct(
        private ProjectReadModel $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return Paginated<\App\Application\Project\DTO\ProjectView> */
    public function __invoke(ProjectFilters $filters, Pagination $pagination): Paginated
    {
        $this->guard->ensureAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned);

        /*
         * Workspace-wide visibility passes null; everyone else is restricted to
         * their assignments. The restriction is applied in SQL, not by filtering
         * a wider result set after the fact.
         */
        $visible = $this->guard->allows(Permission::ProjectsViewAll)
            ? null
            : $this->actor->assignedProjectIds();

        return $this->projects->paginate(
            $this->actor->workspaceId(),
            $this->actor->userId(),
            $visible,
            $filters,
            $pagination,
        );
    }
}
