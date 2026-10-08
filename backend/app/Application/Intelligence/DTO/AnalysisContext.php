<?php

declare(strict_types=1);

namespace App\Application\Intelligence\DTO;

use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Measurement\DTO\TrackingHealthView;
use App\Domain\Analytics\Metric;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * Everything the analysers read, fetched once.
 *
 * The analysers are pure functions over this object: no repository, no clock, no database. That is what makes
 * "a doubling of cost per click on nine clicks is not a finding" a rule somebody can read and test, rather than a
 * condition buried in a query — and it means every analyser sees exactly the same figures, so two of them cannot
 * disagree about what the period's spend was.
 *
 * `tracking` is null when the reader may not see measurement health. An analyser whose input is missing produces
 * nothing, rather than producing something weaker: a finding about tracking assembled without the tracking data
 * would be a guess wearing the same badge as a measurement.
 */
final readonly class AnalysisContext
{
    /**
     * @param  list<BreakdownRow>  $byProvider  the period, ordered by spend
     * @param  list<BreakdownRow>  $byProviderBefore  the period immediately before, for the same dimension
     */
    public function __construct(
        public DateRange $period,
        public DateRange $before,
        /** Totals for the period, carrying each figure's previous value and change. */
        public MetricSet $totals,
        public array $byProvider,
        public array $byProviderBefore,
        public ?TrackingHealthView $tracking,
    ) {}

    public function value(Metric $metric): ?float
    {
        $value = $this->totals->get($metric)?->value;

        return $value === null ? null : (float) $value;
    }

    public function previous(Metric $metric): ?float
    {
        $value = $this->totals->get($metric)?->comparisonValue;

        return $value === null ? null : (float) $value;
    }

    public function label(Metric $metric): string
    {
        return $metric->label();
    }

    /** The period, as words a sentence can contain without naming anybody. */
    public function periodInWords(): string
    {
        return sprintf('%s to %s', $this->period->startDate(), $this->period->endDate());
    }

    /**
     * How a figure moved, as a percentage.
     *
     * Null from a base of zero, which is the one case a change percentage cannot describe: everything is an
     * infinite increase on nothing, and reporting that as a number is how a dashboard comes to claim growth of
     * forty thousand percent.
     */
    public function changePercent(Metric $metric): ?float
    {
        /*
         * `MetricValue::changeRatio()` already holds this arithmetic, including the null from a base of zero.
         * Reimplementing it here would be a second answer to one question, and the two would eventually differ.
         */
        $ratio = $this->totals->get($metric)?->changeRatio();

        return $ratio === null ? null : round($ratio * 100, 1);
    }

    /** Whether both periods carry anything at all for this metric. */
    public function hasBothPeriods(Metric $metric): bool
    {
        return $this->value($metric) !== null && $this->previous($metric) !== null;
    }
}
