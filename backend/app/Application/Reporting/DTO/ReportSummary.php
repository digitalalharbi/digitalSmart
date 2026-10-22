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
        /**
         * What the agency can say about a report's life — never about a reader. Views are a count and a last time, as a
         * link has always held them; nothing here says who looked, or how, or for how long.
         */
        public ?DateTimeImmutable $createdAt = null,
        public ?string $createdByName = null,
        public int $viewCount = 0,
        public ?DateTimeImmutable $lastViewedAt = null,
        /** The soonest an active link lapses, so "expires soon" is visible without opening the report. */
        public ?DateTimeImmutable $nextExpiryAt = null,
        /** Active links that recompute on every open, and active links frozen at a snapshot. */
        public int $liveShareCount = 0,
        public int $frozenShareCount = 0,
    ) {}
}
