<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListDashboards
{
    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<DashboardView> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        return $this->dashboards->visibleTo(
            $this->actor->workspaceId()->value,
            $this->actor->userId()->value,
            $this->visibility->visibleProjectIds(),
        );
    }
}
