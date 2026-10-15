<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Export\Contract\ScheduleStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class DeleteSchedule
{
    public function __construct(
        private ScheduleStore $schedules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::ReportsView);

        $workspaceId = $this->actor->workspaceId()->value;

        if (! $this->schedules->isOwnedBy($workspaceId, $id, $this->actor->userId()->value)) {
            throw ResourceNotFound::of('Schedule');
        }

        $this->schedules->delete($workspaceId, $id);
    }
}
