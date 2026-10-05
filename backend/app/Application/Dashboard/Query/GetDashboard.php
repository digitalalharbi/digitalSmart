<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class GetDashboard
{
    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $id): DashboardView
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;

        $dashboard = $this->dashboards->find(
            $workspaceId,
            $this->actor->userId()->value,
            $this->visibility->visibleProjectIds(),
            $id,
        );

        if ($dashboard === null) {
            // Absent, another workspace's, or private to someone else — one answer
            // for all three, because distinguishing them is a disclosure.
            throw ResourceNotFound::of('Dashboard');
        }

        $this->dashboards->touchLastViewed($workspaceId, $id);

        return $dashboard;
    }
}
