<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use DateTimeImmutable;

/**
 * One row in a list of saved reports.
 *
 * Deliberately does **not** render the report. A list that rendered every report would run one analytics
 * aggregation per row, and the figures would be thrown away as soon as somebody clicked into one of them.
 */
final readonly class ReportSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ReportWindow $window,
        public ReportAudience $audience,
        public ?string $clientName,
        public int $sectionCount,
        /** How many snapshots exist, so a list can say which reports have a frozen record behind them. */
        public int $snapshotCount,
        /** Active shares. The figure somebody checks before they say "nobody outside can see this". */
        public int $activeShareCount,
        public DateTimeImmutable $updatedAt,
    ) {}
}
