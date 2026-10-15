<?php

declare(strict_types=1);

namespace App\Application\Export\Query;

use App\Application\Export\Contract\ScheduleStore;
use App\Application\Export\DTO\ScheduleView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListSchedules
{
    public function __construct(
        private ScheduleStore $schedules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ScheduleView> */
    public function __invoke(?string $reportId = null): array
    {
        $this->guard->ensure(Permission::ReportsView);

        return $this->schedules->mine($this->actor->workspaceId()->value, $this->actor->userId()->value, $reportId);
    }
}
