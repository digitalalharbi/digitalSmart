<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * What the advertising did, as a client reads it.
 *
 * There is no separate comparison set: `MetricSet` already carries each figure's previous value and its change,
 * and a second copy here would be the one that went stale. What is kept is the comparison *period*, because a
 * change figure with no stated baseline is a change figure somebody will misread.
 */
final readonly class PortalPerformance
{
    /**
     * @param  list<SeriesPoint>  $series
     */
    public function __construct(
        public PortalReader $reader,
        public DateRange $period,
        public MetricSet $totals,
        public array $series,
        public DateRange $comparisonPeriod,
    ) {}
}
