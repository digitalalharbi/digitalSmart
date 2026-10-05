<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class DeleteDashboard
{
    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if (! $this->dashboards->isOwnedBy($workspaceId, $id, $this->actor->userId()->value)) {
            /*
             * Only the owner deletes. A shared dashboard anyone could delete is one
             * the team would lose without warning, and "who removed it" is not a
             * question an audit log should have to answer after the fact.
             */
            throw ResourceNotFound::of('Dashboard');
        }

        $this->dashboards->delete($workspaceId, $id);
    }
}
