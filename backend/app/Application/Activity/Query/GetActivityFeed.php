<?php

declare(strict_types=1);

namespace App\Application\Activity\Query;

use App\Application\Activity\Contract\ActivityReadModel;
use App\Application\Activity\DTO\ActivityItem;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * What has been happening, in sentences — for people who run the work, not for people who audit it.
 *
 * The audit trail stays the system of record and stays restricted; this is a curated, scope-filtered reading
 * of it. It needs the agency's own view of clients (`clients.view`) because a client's users hold project
 * access too and must not see the agency's internal events about their own account.
 */
final readonly class GetActivityFeed
{
    public function __construct(
        private ActivityReadModel $activity,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<ActivityItem> */
    public function __invoke(int $limit = 30, ?string $projectId = null, ?string $clientId = null): array
    {
        $this->guard->ensure(Permission::ClientsView);

        return $this->activity->recent(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            max(1, min($limit, 100)),
            $projectId,
            $clientId,
        );
    }
}
