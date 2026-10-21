<?php

declare(strict_types=1);

namespace App\Application\Analytics\Contract;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * Reading aggregated performance.
 *
 * Aggregation happens in SQL, not in PHP: the fact table is the largest in the
 * system and a month of ad-level rows for one client is already tens of thousands
 * of rows. What PHP does is derive the ratios from the returned totals and decide
 * what may honestly be shown.
 *
 * Three rules every implementation must honour, because each one prevents a
 * specific wrong number:
 *
 *  1. **Read one level.** A campaign total is the campaign row, not the sum of its
 *     ads. Providers compute each level themselves and the two disagree.
 *  2. **Never sum reach across entities.** It counts deduplicated people; adding
 *     two campaigns' reach double-counts anyone who saw both. Return null with a
 *     reason instead.
 *  3. **Never mix currencies in a money total.** Return null with a reason. A
 *     number that adds riyals to dollars is worse than no number.
 *
 * And one that is not about arithmetic: every result carries the source state of
 * its weakest input, so a total built from sandbox rows cannot be presented as
 * live.
 */
interface AnalyticsReadModel
{
    /**
     * Totals for the whole range, with an optional comparison period.
     *
     * @param  list<Metric>  $metrics
     */
    public function summary(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        array $metrics,
        ?DateRange $compareTo = null,
    ): MetricSet;

    /**
     * One point per day.
     *
     * Days with no data are returned as empty sets rather than omitted, so a chart
     * shows a gap where delivery stopped instead of drawing a straight line
     * through it.
     *
     * @param  list<Metric>  $metrics
     * @return list<SeriesPoint>
     */
    public function series(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        array $metrics,
    ): array;

    /**
     * Totals grouped by one dimension, ordered by a metric.
     *
     * @param  list<Metric>  $metrics
     * @return list<BreakdownRow>
     */
    public function breakdown(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        Dimension $dimension,
        array $metrics,
        ?Metric $orderBy = null,
        int $limit = 50,
        int $offset = 0,
    ): array;

    /**
     * A daily series for each of several entities at once — the sparkline under a row.
     *
     * One grouped query for all of them, never one per entity, and through the same scope and the same metric derivation as
     * every other read here: a point on a creative's trend is computed by the code that computes its row's figure, so the two
     * cannot disagree. A day with no figure is an empty set, not a zero.
     *
     * @param  list<string>  $keys  the grouping keys of the entities asked about
     * @param  list<Metric>  $metrics
     * @return array<string, list<SeriesPoint>> keyed by grouping key; a key with no rows is absent
     */
    public function seriesBy(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        Dimension $dimension,
        array $keys,
        array $metrics,
    ): array;
}
