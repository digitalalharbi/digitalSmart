<?php

declare(strict_types=1);

namespace App\Application\Project\Contract;

use App\Application\Project\DTO\ProjectFilters;
use App\Application\Project\DTO\ProjectView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Read side for projects.
 *
 * `$visibleProjectIds` is the isolation lever: null means workspace-wide
 * visibility, and a list restricts results to those assignments. The parameter
 * is required rather than optional so a caller cannot widen visibility by
 * forgetting it.
 */
interface ProjectReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return Paginated<ProjectView>
     */
    public function paginate(
        WorkspaceId $workspaceId,
        UserId $actorId,
        ?array $visibleProjectIds,
        ProjectFilters $filters,
        Pagination $pagination,
    ): Paginated;

    /** @param list<string>|null $visibleProjectIds */
    public function find(
        WorkspaceId $workspaceId,
        UserId $actorId,
        ?array $visibleProjectIds,
        ProjectId $projectId,
    ): ?ProjectView;
}
