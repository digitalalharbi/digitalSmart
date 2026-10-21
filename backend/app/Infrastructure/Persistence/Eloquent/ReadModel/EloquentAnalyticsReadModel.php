<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\MetricValue;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Domain\Shared\ValueObject\DateRange;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates daily_metrics in SQL.
 *
 * The queries here are plain and the interesting work is in what they refuse to
 * do. See AnalyticsReadModel for the three rules; this class is where they are
 * enforced, and `MetricValue::unavailable()` is how a refusal reaches the screen
 * with a reason attached instead of becoming a zero.
 */
final readonly class EloquentAnalyticsReadModel implements AnalyticsReadModel
{
    /**
     * Base metrics, and the column each one sums.
     *
     * Counts only. Ratios are derived from these totals, never averaged from
     * per-row ratios — the mean of seven daily CPCs is not the week's CPC.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        Metric::Spend->value => 'spend_minor',
        Metric::Revenue->value => 'revenue_minor',
        Metric::Impressions->value => 'impressions',
        Metric::Reach->value => 'reach',
        Metric::Clicks->value => 'clicks',
        Metric::LinkClicks->value => 'link_clicks',
        Metric::VideoViews->value => 'video_views',
        Metric::VideoCompletions->value => 'video_completions',
        Metric::Engagements->value => 'engagements',
        Metric::Conversions->value => 'conversions',
    ];

    public function summary(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        array $metrics,
        ?DateRange $compareTo = null,
    ): MetricSet {
        $current = $this->aggregate($workspaceId, $range, $filters);
        $previous = $compareTo instanceof DateRange
            ? $this->aggregate($workspaceId, $compareTo, $filters)
            : null;

        return $this->buildSet($metrics, $current, $previous);
    }

    /** @return list<SeriesPoint> */
    public function series(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        array $metrics,
    ): array {
        $rows = $this->baseQuery($workspaceId, $range, $filters)
            ->selectRaw('date')
            ->selectRaw($this->aggregateSelect())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $byDate = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $aggregate */
            $aggregate = (array) $row;
            $byDate[(string) ($aggregate['date'] ?? '')] = $aggregate;
        }

        $points = [];
        $cursor = $range->start;

        /*
         * Every day in the range gets a point, including the empty ones. A chart
         * that omits a day joins the two either side with a straight line, which
         * hides the fact that delivery stopped — the most important thing on the
         * screen that day.
         */
        while ($cursor->format('Y-m-d') <= $range->endDate()) {
            $key = $cursor->format('Y-m-d');

            $points[] = new SeriesPoint(
                date: $cursor,
                metrics: $this->buildSet($metrics, $byDate[$key] ?? null),
            );

            $cursor = $cursor->modify('+1 day');
        }

        return $points;
    }

    /** @return list<BreakdownRow> */
    public function breakdown(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        Dimension $dimension,
        array $metrics,
        ?Metric $orderBy = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $column = $dimension->column();
        $level = $dimension->level();

        $query = $this->baseQuery($workspaceId, $range, $filters, $level)
            ->selectRaw(sprintf('%s as group_key', $column))
            ->selectRaw($this->aggregateSelect())
            // How many distinct entities a group covers, which decides whether
            // reach may be reported for it.
            ->selectRaw('count(distinct entity_id) as entity_count')
            ->whereNotNull($column)
            ->groupBy($column)
            ->limit(max(1, min(500, $limit)))
            ->offset(max(0, $offset));

        $this->joinCreatives($query, $dimension, $workspaceId);

        $orderColumn = $orderBy instanceof Metric ? self::COLUMNS[$orderBy->value] ?? null : null;

        if ($orderColumn !== null) {
            $query->orderByRaw(sprintf('sum(%s) desc nulls last', $orderColumn));
        } else {
            $query->orderByRaw('sum(spend_minor) desc nulls last');
        }

        // A stable order, so a page boundary never shows a row twice or skips one when two groups tie.
        $query->orderBy($column);

        $rows = $query->get();
        $labels = $this->labelsFor($dimension, $this->groupKeys($rows));
        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $aggregate */
            $aggregate = (array) $row;
            $key = (string) ($aggregate['group_key'] ?? '');
            $meta = $labels[$key] ?? null;

            $result[] = new BreakdownRow(
                dimension: $dimension,
                key: $key,
                label: $meta['label'] ?? $this->fallbackLabel($dimension, $key),
                metrics: $this->buildSet($metrics, $aggregate),
                parentKey: $meta['parent'] ?? null,
                externalId: $meta['external_id'] ?? null,
                status: $meta['status'] ?? null,
            );
        }

        return $result;
    }

    public function seriesBy(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        Dimension $dimension,
        array $keys,
        array $metrics,
    ): array {
        if ($keys === []) {
            return [];
        }

        $column = $dimension->column();

        $query = $this->baseQuery($workspaceId, $range, $filters, $dimension->level())
            ->selectRaw(sprintf('%s as group_key', $column))
            ->selectRaw('date')
            ->selectRaw($this->aggregateSelect())
            ->selectRaw('count(distinct entity_id) as entity_count')
            ->whereIn($column, $keys)
            ->groupBy($column, 'date')
            ->orderBy('date');

        $this->joinCreatives($query, $dimension, $workspaceId);

        /** @var array<string, array<string, array<string, mixed>>> $byKey */
        $byKey = [];

        foreach ($query->get() as $row) {
            $aggregate = (array) $row;
            $byKey[(string) ($aggregate['group_key'] ?? '')][(string) ($aggregate['date'] ?? '')] = $aggregate;
        }

        $result = [];

        foreach ($byKey as $key => $days) {
            $points = [];
            $cursor = $range->start;

            while ($cursor->format('Y-m-d') <= $range->endDate()) {
                $points[] = new SeriesPoint(date: $cursor, metrics: $this->buildSet($metrics, $days[$cursor->format('Y-m-d')] ?? null));
                $cursor = $cursor->modify('+1 day');
            }

            $result[$key] = $points;
        }

        return $result;
    }

    /**
     * Reach the creative through the ad, when that is what is being grouped.
     *
     * The join is to a derived table whose two columns have names no metric row has, so every unqualified column in the
     * query still means exactly one thing — and an ad with no creative drops out through the caller's null check instead
     * of being counted under somebody else's.
     */
    private function joinCreatives(Builder $query, Dimension $dimension, string $workspaceId): void
    {
        if ($dimension !== Dimension::Creative) {
            return;
        }

        $query->leftJoinSub(
            DB::table('ads')
                ->where('workspace_id', $workspaceId)
                ->select(['id as joined_ad_id', 'creative_id as joined_creative_id']),
            'creative_link',
            'creative_link.joined_ad_id',
            '=',
            'daily_metrics.ad_id',
        );
    }

    /**
     * One aggregate row for a range.
     *
     * @return array<string, mixed>|null
     */
    private function aggregate(string $workspaceId, DateRange $range, AnalyticsFilters $filters): ?array
    {
        $row = $this->baseQuery($workspaceId, $range, $filters)
            ->selectRaw($this->aggregateSelect())
            ->selectRaw('count(distinct entity_id) as entity_count')
            ->first();

        return $row === null ? null : (array) $row;
    }

    /**
     * The scoped, level-restricted query every aggregate starts from.
     *
     * `entity_type` is always pinned. Without it a query would read campaign, ad
     * group and ad rows together and triple every total — the single easiest way
     * to produce a confidently wrong number from this schema.
     */
    private function baseQuery(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        ?string $level = null,
    ): Builder {
        $level ??= $filters->requiredLevel()->value;

        $query = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)
            ->where('entity_type', $level)
            ->whereBetween('date', [$range->startDate(), $range->endDate()]);

        if ($filters->providers !== []) {
            $query->whereIn('provider', $filters->providers);
        }

        if ($filters->externalAccountIds !== []) {
            $query->whereIn('external_account_id', $filters->externalAccountIds);
        }

        if ($filters->campaignIds !== []) {
            $query->whereIn('campaign_id', $filters->campaignIds);
        }

        if ($filters->adGroupIds !== []) {
            $query->whereIn('ad_group_id', $filters->adGroupIds);
        }

        if ($filters->adIds !== []) {
            $query->whereIn('ad_id', $filters->adIds);
        }

        /*
         * Project and client filters go through the bindings, which is the
         * isolation model: a project sees an account only because it was
         * explicitly bound to it, so that join is the authorisation, not a
         * convenience.
         */
        if ($filters->projectIds !== []) {
            $query->whereIn('external_account_id', function (BuilderContract $sub) use ($filters, $workspaceId): void {
                $sub->select('external_account_id')
                    ->from('project_integration_bindings')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('project_id', $filters->projectIds);
            });
        }

        if ($filters->clientId !== null) {
            $query->whereIn('external_account_id', function (BuilderContract $sub) use ($filters, $workspaceId): void {
                $sub->select('bindings.external_account_id')
                    ->from('project_integration_bindings as bindings')
                    ->join('projects', 'projects.id', '=', 'bindings.project_id')
                    ->where('bindings.workspace_id', $workspaceId)
                    ->where('projects.client_id', $filters->clientId);
            });
        }

        return $query;
    }

    /**
     * The SELECT list: a sum and a non-null count for every base metric.
     *
     * The count is what separates "the provider reported none" from "the provider
     * did not report it". `SUM` over all-null rows returns null in PostgreSQL, but
     * `SUM` over a mix of nulls and zeroes returns 0 — so the count is the only
     * way to know whether any row carried the metric at all.
     */
    private function aggregateSelect(): string
    {
        $parts = ['count(*) as row_count'];

        foreach (self::COLUMNS as $metric => $column) {
            $parts[] = sprintf('sum(%s) as %s_total', $column, $metric);
            $parts[] = sprintf('count(%s) as %s_reported', $column, $metric);
        }

        // The weakest source present decides what the whole total may be called,
        // and the currency set decides whether money may be added at all.
        $parts[] = "string_agg(distinct source, ',') as sources";
        $parts[] = "string_agg(distinct currency, ',') as currencies";

        return implode(', ', $parts);
    }

    /**
     * Turn one aggregate row into presentable figures.
     *
     * @param  list<Metric>  $metrics
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>|null  $comparison
     */
    private function buildSet(array $metrics, ?array $row, ?array $comparison = null): MetricSet
    {
        $rowCount = (int) ($row['row_count'] ?? 0);
        $source = $this->sourceOf($row);
        $currencies = $this->currenciesOf($row);
        $currency = count($currencies) === 1 ? $currencies[0] : null;
        $entityCount = (int) ($row['entity_count'] ?? 0);
        $values = [];

        foreach ($metrics as $metric) {
            $values[$metric->value] = $this->valueFor(
                $metric,
                $row,
                $comparison,
                $source,
                $currency,
                count($currencies),
                $entityCount,
            );
        }

        return new MetricSet(
            values: $values,
            source: $source,
            currency: $currency,
            rowCount: $rowCount,
        );
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @param  array<string, mixed>|null  $comparison
     */
    private function valueFor(
        Metric $metric,
        ?array $row,
        ?array $comparison,
        MetricSource $source,
        ?string $currency,
        int $currencyCount,
        int $entityCount,
    ): MetricValue {
        if ($row === null || (int) ($row['row_count'] ?? 0) === 0) {
            return MetricValue::of($metric, null, $source, $currency);
        }

        /*
         * Money cannot cross currencies. Converting would need a rate, a date and
         * a decision about which rate — none of which belongs in a read model, and
         * all of which a client would want to see stated.
         */
        if ($metric->isMonetary() && $currencyCount > 1) {
            return MetricValue::unavailable(
                $metric,
                'Covers more than one currency, so it cannot be totalled. Filter to one ad account or currency.',
                $source,
            );
        }

        /*
         * Reach counts deduplicated people. Across two entities the platform
         * knows who saw both and we do not, so any sum overstates it.
         */
        if (! $metric->isAdditiveAcrossEntities() && $entityCount > 1) {
            return MetricValue::unavailable(
                $metric,
                sprintf(
                    'Reach is deduplicated by the platform, so it cannot be added across %d entities. '
                    .'Filter to one to see it.',
                    $entityCount,
                ),
                $source,
            );
        }

        if ($metric->isDerived()) {
            return $this->derive($metric, $row, $comparison, $source, $currency, $entityCount);
        }

        $value = $this->baseValue($metric, $row);

        if ($value === null) {
            return MetricValue::unavailable(
                $metric,
                'Not reported by this platform for the selected period.',
                $source,
            );
        }

        return MetricValue::of(
            $metric,
            $value,
            $source,
            $currency,
            $comparison === null ? null : $this->baseValue($metric, $comparison),
        );
    }

    /**
     * A summed base metric, or null when no row reported it.
     *
     * @param  array<string, mixed>  $row
     */
    private function baseValue(Metric $metric, array $row): int|float|null
    {
        $column = self::COLUMNS[$metric->value] ?? null;

        if ($column === null) {
            // Conversion-action metrics (purchases, leads, installs) live in
            // conversion_metrics, not here. Returning null is accurate: this
            // query did not read them.
            return null;
        }

        if ((int) ($row[$metric->value.'_reported'] ?? 0) === 0) {
            return null;
        }

        $total = $row[$metric->value.'_total'] ?? null;

        if ($total === null) {
            return null;
        }

        return $metric === Metric::Conversions ? (float) $total : (int) $total;
    }

    /**
     * Compute a ratio from the totals.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $comparison
     */
    private function derive(
        Metric $metric,
        array $row,
        ?array $comparison,
        MetricSource $source,
        ?string $currency,
        int $entityCount,
    ): MetricValue {
        $value = $this->ratio($metric, $row, $entityCount);

        if ($value === null) {
            return MetricValue::unavailable(
                $metric,
                sprintf(
                    'Needs %s, which this platform did not report for the selected period.',
                    implode(' and ', array_map(
                        static fn (Metric $dependency): string => strtolower($dependency->label()),
                        $metric->dependsOn(),
                    )),
                ),
                $source,
            );
        }

        return MetricValue::of(
            $metric,
            $value,
            $source,
            $currency,
            $comparison === null ? null : $this->ratio($metric, $comparison, (int) ($comparison['entity_count'] ?? 0)),
        );
    }

    /** @param array<string, mixed> $row */
    private function ratio(Metric $metric, array $row, int $entityCount): ?float
    {
        $spend = $this->baseValue(Metric::Spend, $row);
        $impressions = $this->baseValue(Metric::Impressions, $row);
        $clicks = $this->baseValue(Metric::Clicks, $row);

        return match ($metric) {
            Metric::Roas => $this->divide($this->baseValue(Metric::Revenue, $row), $spend),
            // Only meaningful for a single entity, which the caller has checked.
            Metric::Frequency => $entityCount > 1
                ? null
                : $this->divide($impressions, $this->baseValue(Metric::Reach, $row)),
            Metric::Ctr => $this->percentage($clicks, $impressions),
            Metric::LinkCtr => $this->percentage($this->baseValue(Metric::LinkClicks, $row), $impressions),
            Metric::Cpc => $this->divide($spend, $clicks),
            Metric::Cpm => $this->multiply($this->divide($spend, $impressions), 1000),
            Metric::Cpv => $this->divide($spend, $this->baseValue(Metric::VideoViews, $row)),
            Metric::VideoCompletionRate => $this->percentage(
                $this->baseValue(Metric::VideoCompletions, $row),
                $this->baseValue(Metric::VideoViews, $row),
            ),
            Metric::ConversionRate => $this->percentage($this->baseValue(Metric::Conversions, $row), $clicks),
            Metric::CostPerConversion => $this->divide($spend, $this->baseValue(Metric::Conversions, $row)),
            default => null,
        };
    }

    private function divide(int|float|null $numerator, int|float|null $denominator): ?float
    {
        if ($numerator === null || $denominator === null) {
            return null;
        }

        // A zero denominator has no ratio. Returning 0 would read as "free", and
        // returning infinity reads as a number.
        return (float) $denominator === 0.0 ? null : (float) $numerator / (float) $denominator;
    }

    private function percentage(int|float|null $part, int|float|null $whole): ?float
    {
        $ratio = $this->divide($part, $whole);

        return $ratio === null ? null : $ratio * 100;
    }

    private function multiply(?float $value, float $factor): ?float
    {
        return $value === null ? null : $value * $factor;
    }

    /** @param array<string, mixed>|null $row */
    private function sourceOf(?array $row): MetricSource
    {
        $raw = $row['sources'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return MetricSource::Live;
        }

        $sources = [];

        foreach (explode(',', $raw) as $value) {
            $source = MetricSource::tryFrom(trim($value));

            if ($source instanceof MetricSource) {
                $sources[] = $source;
            }
        }

        // The whole total is only as trustworthy as its weakest row.
        return MetricSource::weakest($sources);
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return list<string>
     */
    private function currenciesOf(?array $row): array
    {
        $raw = $row['currencies'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>  $rows
     * @return list<string>
     */
    private function groupKeys(mixed $rows): array
    {
        $keys = [];

        foreach ($rows as $row) {
            $key = ((array) $row)['group_key'] ?? null;

            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Human labels for the grouping keys, in one query rather than per row.
     *
     * @param  list<string>  $keys
     * @return array<string, array{label: string, parent?: string, external_id?: string, status?: string}>
     */
    private function labelsFor(Dimension $dimension, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        [$table, $parentColumn] = match ($dimension) {
            Dimension::Campaign => ['campaigns', null],
            Dimension::AdGroup => ['ad_groups', 'campaign_id'],
            Dimension::Ad => ['ads', 'ad_group_id'],
            Dimension::ExternalAccount => ['external_accounts', null],
            Dimension::Creative => ['creatives', null],
            default => [null, null],
        };

        if ($table === null) {
            return [];
        }

        $columns = ['id', 'name', 'external_id'];

        if (! in_array($table, ['external_accounts', 'creatives'], true)) {
            $columns[] = 'status';
        }

        if ($parentColumn !== null) {
            $columns[] = $parentColumn;
        }

        $labels = [];

        foreach (DB::table($table)->whereIn('id', $keys)->get($columns) as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;
            $id = (string) ($record['id'] ?? '');

            $entry = ['label' => (string) ($record['name'] ?? $id)];

            if (isset($record['external_id'])) {
                $entry['external_id'] = (string) $record['external_id'];
            }

            if (isset($record['status'])) {
                $entry['status'] = (string) $record['status'];
            }

            if ($parentColumn !== null && isset($record[$parentColumn])) {
                $entry['parent'] = (string) $record[$parentColumn];
            }

            $labels[$id] = $entry;
        }

        return $labels;
    }

    private function fallbackLabel(Dimension $dimension, string $key): string
    {
        return match ($dimension) {
            /*
             * The provider's display name, from the enum that already defines it.
             * It is resolved here rather than in the client because the label is a
             * domain fact: two places deciding it is how one screen ends up saying
             * "Meta Ads" and another "google_ads".
             */
            Dimension::Provider => Provider::tryFrom($key)?->label() ?? $key,
            Dimension::Date => $key,
            /*
             * Defensive. The foreign keys cascade, so deleting a campaign takes its
             * metric rows with it and an unnameable group should not arise — but a
             * figure with no name is one a reader cannot act on, so if one ever does
             * it says so rather than rendering a blank cell.
             */
            default => 'Unknown ('.substr($key, 0, 8).')',
        };
    }
}
