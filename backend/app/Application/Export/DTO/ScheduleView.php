<?php

declare(strict_types=1);

namespace App\Application\Export\DTO;

use App\Domain\Export\ExportKind;
use App\Domain\Export\ScheduleCadence;
use DateTimeImmutable;

final readonly class ScheduleView
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $userId,
        public string $reportId,
        public ExportKind $kind,
        public ScheduleCadence $cadence,
        public int $hour,
        public ?int $weekday,
        public ?int $dayOfMonth,
        public ?DateTimeImmutable $nextRunAt,
        public ?DateTimeImmutable $lastRunAt,
        public ?string $lastError,
    ) {}
}
