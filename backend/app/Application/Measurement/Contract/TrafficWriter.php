<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\TrafficRecord;
use App\Domain\Advertising\MetricSource;

/** Writes traffic rows, replacing rather than accumulating. */
interface TrafficWriter
{
    /** @param list<TrafficRecord> $records */
    public function upsert(
        string $workspaceId,
        string $sourceId,
        array $records,
        MetricSource $source,
        ?string $timeZone,
    ): int;
}
