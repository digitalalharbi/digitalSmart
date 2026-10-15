<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Export\Contract\ScheduleStore;
use App\Application\Export\DTO\ScheduleView;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;

/** Have a report made again on a rhythm, for the person who asked — and as them, every time. */
final readonly class ScheduleReport
{
    public function __construct(
        private ScheduleStore $schedules,
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $reportId,
        ExportKind $kind,
        ScheduleCadence $cadence,
        int $hour = 6,
        ?int $weekday = null,
        ?int $dayOfMonth = null,
    ): ScheduleView {
        $this->guard->ensure(Permission::ReportsView);

        $workspaceId = $this->actor->workspaceId()->value;

        $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        $now = $this->clock->now();
        $hour = min(max($hour, 0), 23);

        return $this->schedules->create(
            $workspaceId,
            $this->actor->userId()->value,
            $reportId,
            $kind,
            $cadence,
            $hour,
            $weekday,
            $dayOfMonth,
            $cadence->next($now, $hour, $weekday, $dayOfMonth),
            $now,
        );
    }
}
