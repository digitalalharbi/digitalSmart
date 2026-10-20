<?php

declare(strict_types=1);

namespace App\Application\Reporting\Service;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Commerce\DTO\StoreRollupRow;
use App\Application\Reporting\DTO\ReportNarrowing;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Reporting\DTO\SharedFilterRequest;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Domain\Reporting\Exception\FilterOutsideScope;
use App\Domain\Reporting\SectionKind;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * What a shared link may be narrowed to, and what a visitor's filter narrows it to.
 *
 * The invariant is one sentence: **the token defines access and a filter only narrows it.** So the scope is derived
 * first — from the report, its client, its filters and its resolved period, never from the request — and the request is
 * then looked up *inside* that scope. A platform that has no rows in the report, a campaign the report does not
 * contain, a store of another client, a day outside the period: none of them is found, and a value that is not found
 * is refused. There is no code path from a request value to a query that does not pass through a lookup in scope.
 *
 * Campaigns and stores are offered by **opaque keys**, derived from the link's own id, so the page never holds an
 * internal id and a key from one link means nothing on another.
 */
final readonly class NarrowSharedReport
{
    /** How many campaigns the page may be offered, and may be asked about. A search box covers the rest. */
    private const int CAMPAIGN_LIMIT = 100;

    public function __construct(
        private AnalyticsReadModel $analytics,
        private StoreAnalyticsReadModel $stores,
    ) {}

    /**
     * What this link can be narrowed by. Only what the report actually contains: a dimension with one value is not a
     * filter, and an empty control is not offered.
     *
     * @return array{
     *     platforms: list<array{key: string, label: string}>,
     *     campaigns: list<array{key: string, label: string}>,
     *     stores: list<array{key: string, label: string}>,
     * }
     */
    public function options(string $shareId, string $workspaceId, SavedReport $report, DateRange $period): array
    {
        $filters = $report->definition->filters;

        $platforms = array_map(
            static fn ($row): array => [
                'key' => $row->key,
                'label' => Provider::tryFrom($row->key)?->label() ?? $row->label,
            ],
            $this->analytics->breakdown($workspaceId, $period, $filters, Dimension::Provider, [Metric::Spend], Metric::Spend, 20),
        );

        $campaigns = array_map(
            fn ($row): array => ['key' => $this->key($shareId, $row->key), 'label' => $row->label],
            $this->analytics->breakdown($workspaceId, $period, $filters, Dimension::Campaign, [Metric::Spend], Metric::Spend, self::CAMPAIGN_LIMIT),
        );

        return [
            'platforms' => count($platforms) > 1 ? $platforms : [],
            'campaigns' => count($campaigns) > 1 ? $campaigns : [],
            'stores' => $this->storeOptions($shareId, $workspaceId, $report, $period),
        ];
    }

    /**
     * Intersect a request with the link's scope.
     *
     * @throws FilterOutsideScope
     */
    public function narrow(
        string $shareId,
        string $workspaceId,
        SavedReport $report,
        DateRange $period,
        SharedFilterRequest $request,
    ): ReportNarrowing {
        if ($request->isEmpty()) {
            return new ReportNarrowing;
        }

        $filters = $report->definition->filters;

        return new ReportNarrowing(
            period: $this->period($period, $request),
            providers: $this->platforms($workspaceId, $filters, $period, $request),
            campaignIds: $this->campaigns($shareId, $workspaceId, $filters, $period, $request),
            storeId: $this->store($shareId, $workspaceId, $report, $period, $request),
        );
    }

    private function period(DateRange $scope, SharedFilterRequest $request): ?DateRange
    {
        if ($request->from === null && $request->until === null) {
            return null;
        }

        $from = $request->from ?? $scope->start;
        $until = $request->until ?? $scope->end;

        if ($from > $until || $from->format('Y-m-d') < $scope->startDate() || $until->format('Y-m-d') > $scope->endDate()) {
            throw FilterOutsideScope::period();
        }

        // The same range is not a narrowing.
        if ($from->format('Y-m-d') === $scope->startDate() && $until->format('Y-m-d') === $scope->endDate()) {
            return null;
        }

        return new DateRange($from, $until);
    }

    /** @return list<string> */
    private function platforms(string $workspaceId, AnalyticsFilters $base, DateRange $period, SharedFilterRequest $request): array
    {
        if ($request->platforms === []) {
            return [];
        }

        $present = array_map(
            static fn ($row): string => $row->key,
            $this->analytics->breakdown($workspaceId, $period, $base, Dimension::Provider, [Metric::Spend], Metric::Spend, 20),
        );

        foreach ($request->platforms as $platform) {
            if (! in_array($platform, $present, true)) {
                throw FilterOutsideScope::unknown('platform');
            }
        }

        return array_values(array_unique($request->platforms));
    }

    /** @return list<string> */
    private function campaigns(string $shareId, string $workspaceId, AnalyticsFilters $base, DateRange $period, SharedFilterRequest $request): array
    {
        if ($request->campaigns === []) {
            return [];
        }

        $byKey = [];

        foreach ($this->analytics->breakdown($workspaceId, $period, $base, Dimension::Campaign, [Metric::Spend], Metric::Spend, self::CAMPAIGN_LIMIT) as $row) {
            $byKey[$this->key($shareId, $row->key)] = $row->key;
        }

        $ids = [];

        foreach ($request->campaigns as $key) {
            if (! isset($byKey[$key])) {
                throw FilterOutsideScope::unknown('campaign');
            }

            $ids[] = $byKey[$key];
        }

        return array_values(array_unique($ids));
    }

    private function store(string $shareId, string $workspaceId, SavedReport $report, DateRange $period, SharedFilterRequest $request): ?string
    {
        if ($request->store === null) {
            return null;
        }

        foreach ($this->storeRows($workspaceId, $report, $period) as $row) {
            if ($this->key($shareId, $row->storeId) === $request->store) {
                return $row->storeId;
            }
        }

        throw FilterOutsideScope::unknown('store');
    }

    /** @return list<array{key: string, label: string}> */
    private function storeOptions(string $shareId, string $workspaceId, SavedReport $report, DateRange $period): array
    {
        $rows = $this->storeRows($workspaceId, $report, $period);

        if (count($rows) < 2) {
            return [];
        }

        return array_map(
            fn (StoreRollupRow $row): array => ['key' => $this->key($shareId, $row->storeId), 'label' => $row->storeName],
            $rows,
        );
    }

    /**
     * The client's stores, under the authority the report's own store section names.
     *
     * Empty when the report has no store section: a store filter on a report that shows no store would be a control
     * attached to nothing.
     *
     * @return list<StoreRollupRow>
     */
    private function storeRows(string $workspaceId, SavedReport $report, DateRange $period): array
    {
        $definition = $report->definition;

        if ($definition->clientId === null) {
            return [];
        }

        foreach ($definition->sections as $section) {
            if ($section->kind === SectionKind::Store && $section->revenueSource !== null) {
                return $this->stores->byStore(new StoreQuery(
                    workspaceId: $workspaceId,
                    revenueSource: $section->revenueSource,
                    from: $period->start,
                    until: $period->end,
                    visibleProjectIds: null,
                    clientId: $definition->clientId,
                    limit: 20,
                ));
            }
        }

        return [];
    }

    /** A key that means something only on this link. */
    private function key(string $shareId, string $entityId): string
    {
        return substr(hash('sha256', $shareId.'|'.$entityId), 0, 16);
    }
}
