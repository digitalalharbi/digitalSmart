<?php

declare(strict_types=1);

namespace App\Application\Export\Contract;

use App\Application\Export\DTO\ScheduleView;
use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;
use DateTimeImmutable;

interface ScheduleStore
{
    public function create(
        string $workspaceId,
        string $userId,
        string $reportId,
        ExportKind $kind,
        ScheduleCadence $cadence,
        int $hour,
        ?int $weekday,
        ?int $dayOfMonth,
        DateTimeImmutable $nextRunAt,
        DateTimeImmutable $at,
    ): ScheduleView;

    /** @return list<ScheduleView> the requester's own, soonest first */
    public function mine(string $workspaceId, string $userId, ?string $reportId): array;

    public function isOwnedBy(string $workspaceId, string $id, string $userId): bool;

    public function delete(string $workspaceId, string $id): void;

    /** @return list<ScheduleView> enabled schedules whose time has come, across every workspace */
    public function due(DateTimeImmutable $now, int $limit): array;

    public function ran(string $id, DateTimeImmutable $at, DateTimeImmutable $nextRunAt): void;

    /** Stops a schedule that can no longer run, and keeps why. */
    public function halt(string $id, string $reason, DateTimeImmutable $at): void;
}
