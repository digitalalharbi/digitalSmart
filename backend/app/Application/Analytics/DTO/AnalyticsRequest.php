<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;

/**
 * What a caller asked for.
 *
 * One object for every analytics surface — scorecard, chart, table — because they
 * differ only in how the same query is grouped. A saved view is this object
 * persisted, and a dashboard widget is this object plus a chart type.
 */
final readonly class AnalyticsRequest
{
    /** @param list<Metric> $metrics */
    public function __construct(
        public DateRange $range,
        public AnalyticsFilters $filters,
        public array $metrics,
        public ?DateRange $compareTo = null,
        public ?Dimension $dimension = null,
        public ?Metric $orderBy = null,
        public int $limit = 50,
    ) {}

    /** The default scorecard: the figures an agency looks at first. */
    public static function scorecard(DateRange $range, AnalyticsFilters $filters, ?DateRange $compareTo = null): self
    {
        return new self(
            range: $range,
            filters: $filters,
            metrics: [
                Metric::Spend,
                Metric::Revenue,
                Metric::Roas,
                Metric::Conversions,
                Metric::CostPerConversion,
                Metric::Impressions,
                Metric::Clicks,
                Metric::Ctr,
                Metric::Cpc,
                Metric::Cpm,
            ],
            compareTo: $compareTo,
        );
    }
}
