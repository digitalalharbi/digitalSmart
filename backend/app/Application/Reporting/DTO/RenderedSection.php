<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Reporting\SectionKind;

/**
 * One block of a report, rendered.
 *
 * Carries its own **source state** rather than inheriting one from the report, because a report can legitimately
 * mix them: a trend built from synced platform rows beside a ranking built from sandbox fixtures is two different
 * claims, and one badge over both of them would be the weaker claim presented as the stronger — or the stronger
 * presented as the weaker, which is how people learn to ignore the badge.
 *
 * `isEmpty` is its own flag. A section with no data says so; it never renders as a row of zeroes, because zero
 * spend and "nothing recorded" mean opposite things to anybody deciding a budget.
 */
final readonly class RenderedSection
{
    /**
     * @param  list<Metric>  $metrics
     * @param  list<SeriesPoint>  $series
     * @param  list<BreakdownRow>  $rows
     * @param  list<string>  $caveats
     */
    public function __construct(
        public SectionKind $kind,
        public string $title,
        public array $metrics,
        public MetricSource $source,
        public bool $isEmpty,
        public array $caveats,
        /*
         * The comparison is not a second set: the read model puts the earlier figure on each `MetricValue`, where
         * the change ratio and its "no percentage from zero" rule already live.
         */
        public ?MetricSet $totals = null,
        public array $series = [],
        public array $rows = [],
        public ?Dimension $dimension = null,
        public ?string $currency = null,
    ) {}

    public function requiresLabel(): bool
    {
        return $this->source->requiresLabel();
    }
}
