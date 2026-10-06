<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;

/**
 * What one measurement sync actually achieved.
 *
 * Reported, not inferred. The caveats are the field that matters: a run can succeed completely
 * and still have read figures that are estimates, and a caller that only sees `succeeded` would
 * present them as totals.
 */
final readonly class MeasurementSyncResult
{
    /** @param list<string> $caveats */
    public function __construct(
        public SyncStatus $status,
        public int $daysRead = 0,
        public int $rowsWritten = 0,
        public int $apiCalls = 0,
        /** The weakest label any row in this run carries. */
        public MetricSource $source = MetricSource::Synced,
        public array $caveats = [],
        public ?string $failureMessage = null,
        /** The most recent day any event was seen on, which is what freshness is judged by. */
        public ?string $lastEventDate = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === SyncStatus::Succeeded;
    }
}
