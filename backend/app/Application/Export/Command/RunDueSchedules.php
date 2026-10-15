<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Export\Contract\ScheduleStore;
use App\Application\Export\DTO\ExportView;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use Throwable;

/**
 * Queue what is due. Returns the exports it created; the caller puts them on the queue.
 *
 * Each runs as its owner: the actor is bound to them and the report is re-checked through `RequestExport`, so
 * a person who has since lost sight of a report stops receiving it rather than going on receiving it as
 * whoever last had access. A schedule that can no longer run is halted with the reason, not retried for ever.
 */
final readonly class RunDueSchedules
{
    public function __construct(
        private ScheduleStore $schedules,
        private RequestExport $request,
        private ActorContextBinder $actors,
        private Clock $clock,
    ) {}

    /** @return list<ExportView> */
    public function __invoke(int $limit = 200): array
    {
        $now = $this->clock->now();
        $created = [];

        foreach ($this->schedules->due($now, $limit) as $schedule) {
            try {
                $this->actors->bind(
                    new \App\Domain\Identity\UserId($schedule->userId),
                    new \App\Domain\Workspace\WorkspaceId($schedule->workspaceId),
                );

                $created[] = ($this->request)($schedule->kind, $schedule->reportId);

                $this->schedules->ran(
                    $schedule->id,
                    $now,
                    $schedule->cadence->next($now, $schedule->hour, $schedule->weekday, $schedule->dayOfMonth),
                );
            } catch (Throwable $failure) {
                $this->schedules->halt($schedule->id, 'The report is no longer available to its owner.', $now);
                report($failure);
            } finally {
                $this->actors->clear();
            }
        }

        return $created;
    }
}
