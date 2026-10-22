<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\CreativeRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Application\Analytics\Service\CreativeIntelligenceReader;
use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Leads\Contract\LeadReadModel;
use App\Application\Reporting\DTO\CreativeSection;
use App\Application\Reporting\DTO\LeadFigures;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\ReportNarrowing;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Reporting\DTO\StoreFigures;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Domain\Reporting\Comparability;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\CreativeView;
use App\Domain\Reporting\RenderMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\SectionKind;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * Turn a saved report into figures.
 *
 * Composes the existing analytics read model rather than writing its own SQL. That is the whole point: the rules
 * about reading one level, never summing reach, never mixing currencies and carrying the weakest source state are
 * already there and already tested, and a report that queried the fact table directly would be a second
 * implementation of them — the one that forgets.
 *
 * Three decisions this class makes on its own.
 *
 * **The clock is read once.** Every section, the period and the comparison are resolved against one moment, so a
 * report cannot contain two periods because it was rendered across midnight.
 *
 * **Comparison figures are fetched only where they mean something.** `SectionKind::supportsComparison()` is true
 * for a scorecard alone — and the comparison is skipped entirely when `Comparability` says the periods cannot be
 * compared, because fetching a number the interface must refuse to show is work done to produce a temptation.
 *
 * **A client-safe render never reads the internal note.** Not blanked afterwards: the field is not passed on. A
 * value that reaches a DTO is a value one serialiser away from a response.
 */
final readonly class RenderReport
{
    /** @var list<Metric> */
    private const array CREATIVE_METRICS = [Metric::Spend, Metric::Impressions, Metric::Clicks, Metric::Ctr, Metric::Conversions, Metric::CostPerConversion, Metric::Roas];

    /** @var list<Metric> */
    private const array CREATIVE_TREND = [Metric::Spend, Metric::Ctr, Metric::CostPerConversion, Metric::Conversions, Metric::Roas, Metric::Frequency];

    public function __construct(
        private AnalyticsReadModel $analytics,
        private StoreAnalyticsReadModel $stores,
        private LeadReadModel $leads,
        private CreativeIntelligenceReader $creatives,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $workspaceId,
        SavedReport $report,
        ?ReportAudience $as = null,
        ?ReportNarrowing $narrowing = null,
    ): RenderedReport {
        $now = $this->clock->now();
        $definition = $report->definition;
        $audience = $as ?? $definition->audience;

        $period = $definition->window->resolve(
            $now,
            $definition->customFrom,
            $definition->customUntil,
        );

        /*
         * A visitor's narrowing, already intersected with this report's scope. It can only remove: a sub-range of the
         * period, a subset of the platforms and campaigns the report holds, one of its stores.
         *
         * The comparison is dropped for a narrowed period rather than recomputed. The equally long stretch before a
         * sub-range reaches back past the period the report was written to show, and a percentage change built on
         * figures the link never covered would be a way of widening it.
         */
        $narrowedPeriod = $narrowing?->period;
        $comparisonPeriod = $narrowedPeriod === null ? $definition->comparison->resolve($period) : null;
        $period = $narrowedPeriod ?? $period;

        if ($narrowing !== null && $narrowing->isActive()) {
            $definition = $this->narrowed($definition, $narrowing);
        }

        $comparability = $comparisonPeriod === null
            ? Comparability::none()
            : Comparability::of($period, $comparisonPeriod, $definition->window->isIncomplete());

        $sections = [];
        $sources = [];

        foreach ($definition->sections as $index => $section) {
            $rendered = $this->section(
                $workspaceId,
                $definition,
                $section,
                $period,
                $comparability->isComparable ? $comparisonPeriod : null,
                $index,
                $narrowing,
            );

            $sections[] = $rendered;
            $sources[] = $rendered->source;
        }

        return new RenderedReport(
            id: $report->id,
            name: $definition->name,
            description: $definition->description,
            period: $period,
            comparisonPeriod: $comparisonPeriod,
            comparison: $definition->comparison,
            comparability: $comparability,
            audience: $audience,
            mode: RenderMode::Live,
            /*
             * The weakest state anywhere in the report. A report whose worst section is sandbox is a sandbox
             * report, whatever its best section says.
             */
            source: MetricSource::weakest($sources),
            sections: $sections,
            renderedAt: $now,
            clientName: $report->clientName,
            // Never read for a client-safe render. See the note at the top of this class.
            internalNote: $audience->allowsInternalNotes() ? $definition->internalNote : null,
            isPeriodIncomplete: $definition->window->isIncomplete(),
        );
    }

    /**
     * The definition with the visitor's narrowing laid over its filters.
     *
     * A requested platform or campaign replaces the report's own list, which is safe only because
     * `NarrowSharedReport` found every one of them inside that scope first; this method never receives a raw request.
     */
    private function narrowed(ReportDefinition $definition, ReportNarrowing $narrowing): ReportDefinition
    {
        $base = $definition->filters;

        return new ReportDefinition(
            name: $definition->name,
            window: $definition->window,
            comparison: $definition->comparison,
            audience: $definition->audience,
            filters: new AnalyticsFilters(
                providers: $narrowing->providers !== [] ? $narrowing->providers : $base->providers,
                externalAccountIds: $base->externalAccountIds,
                campaignIds: $narrowing->campaignIds !== [] ? $narrowing->campaignIds : $base->campaignIds,
                adGroupIds: $base->adGroupIds,
                adIds: $base->adIds,
                projectIds: $base->projectIds,
                clientId: $base->clientId,
            ),
            sections: $definition->sections,
            description: $definition->description,
            clientId: $definition->clientId,
            customFrom: $definition->customFrom,
            customUntil: $definition->customUntil,
            internalNote: $definition->internalNote,
        );
    }

    private function section(
        string $workspaceId,
        ReportDefinition $definition,
        SectionDefinition $section,
        DateRange $period,
        ?DateRange $comparisonPeriod,
        int $index,
        ?ReportNarrowing $narrowing = null,
    ): RenderedSection {
        $title = $section->title ?? $this->defaultTitle($section, $index);
        $caveats = $this->caveats($definition->comparison, $section);

        /*
         * A section that cannot follow an active filter is not shown under it. Leads and a store's orders carry no
         * platform or campaign of the ad platforms, so a "Meta" chip over their figures would be a claim they cannot
         * keep — they would be the whole client's, wearing a narrower label.
         */
        if ($narrowing !== null && $narrowing->narrowsAdvertising() && in_array($section->kind, [SectionKind::Lead, SectionKind::Store], true)) {
            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: [],
                source: MetricSource::Synced,
                isEmpty: true,
                caveats: ['This section cannot be narrowed by platform or campaign, so it is not shown for this selection.'],
            );
        }

        if ($section->kind === SectionKind::Lead) {
            return $this->lead($workspaceId, $definition, $section, $period, $title, $caveats);
        }

        if ($section->kind === SectionKind::Creative) {
            return $this->creative($workspaceId, $definition, $section, $period, $title, $caveats, $narrowing);
        }

        if ($section->kind === SectionKind::Store) {
            return $this->store($workspaceId, $definition, $section, $period, $comparisonPeriod, $title, $caveats, $narrowing?->storeId);
        }

        if ($section->kind === SectionKind::Trend) {
            $series = $this->analytics->series(
                $workspaceId,
                $period,
                $definition->filters,
                $section->metrics,
            );

            $sources = array_map(
                static fn (SeriesPoint $point): MetricSource => $point->metrics->source,
                $series,
            );

            /*
             * A trend is empty when every day is. Days with no delivery come back as empty sets rather than being
             * omitted — the read model's own rule — so a chart shows a gap where spending stopped instead of
             * drawing a straight line through it, and this counts those gaps as nothing rather than as zeroes.
             */
            $hasAny = false;

            foreach ($series as $point) {
                if (! $point->metrics->isEmpty()) {
                    $hasAny = true;

                    break;
                }
            }

            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: $section->metrics,
                source: $sources === [] ? MetricSource::Synced : MetricSource::weakest($sources),
                isEmpty: ! $hasAny,
                caveats: $caveats,
                series: $series,
                currency: $this->currencyOf($series === [] ? null : $series[0]->metrics),
            );
        }

        if ($section->kind === SectionKind::Scorecard) {
            /*
             * The comparison goes through the read model's own `compareTo`, not a second call. That puts the earlier
             * figure on each `MetricValue`, which is where the change ratio already lives — along with its rule that
             * there is no percentage from a base of zero, because "up from nothing" is not a number anybody should
             * quote. Two separate summaries would have meant computing that ratio here, a second time, differently.
             *
             * Passed only when the periods are comparable: fetching a figure the interface must refuse to show is
             * work done to produce a temptation.
             */
            $totals = $this->analytics->summary(
                $workspaceId,
                $period,
                $definition->filters,
                $section->metrics,
                $comparisonPeriod,
            );

            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: $section->metrics,
                source: $totals->source,
                isEmpty: $totals->isEmpty(),
                caveats: $caveats,
                totals: $totals,
                currency: $totals->currency,
            );
        }

        // A ranking or a table: the same read, shown two ways.
        $dimension = $section->dimension;

        if ($dimension === null) {
            // Unreachable through the use cases, which validate first. Kept honest rather than assumed.
            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: $section->metrics,
                source: MetricSource::Synced,
                isEmpty: true,
                caveats: $caveats,
            );
        }

        $rows = $this->analytics->breakdown(
            $workspaceId,
            $period,
            $definition->filters,
            $dimension,
            $section->metrics,
            $section->metrics[0] ?? null,
            $section->resolvedLimit(),
        );

        $sources = array_map(
            static fn (BreakdownRow $row): MetricSource => $row->metrics->source,
            $rows,
        );

        return new RenderedSection(
            kind: $section->kind,
            title: $title,
            metrics: $section->metrics,
            source: $sources === [] ? MetricSource::Synced : MetricSource::weakest($sources),
            isEmpty: $rows === [],
            caveats: $caveats,
            rows: $rows,
            dimension: $dimension,
            currency: $rows === [] ? null : $this->currencyOf($rows[0]->metrics),
        );
    }

    /**
     * How the client's recorded leads turned out.
     *
     * Aggregates only, read for the report's own client and never across clients (`ReportDefinition` refuses a lead
     * section without one, and the query is narrowed by it again here). Nothing about an individual lead is read into
     * the figures. No comparison period: a lead's outcome keeps changing after the period closes, so an earlier
     * month's rate is not a settled number to compare with.
     *
     * @param  list<string>  $caveats
     */
    private function lead(
        string $workspaceId,
        ReportDefinition $definition,
        SectionDefinition $section,
        DateRange $period,
        string $title,
        array $caveats,
    ): RenderedSection {
        if ($definition->clientId === null) {
            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: [],
                source: MetricSource::Synced,
                isEmpty: true,
                caveats: $caveats,
            );
        }

        $from = $period->start;
        $until = $period->end->setTime(23, 59, 59);
        $clientId = $definition->clientId;

        $figures = new LeadFigures(
            funnel: $this->leads->funnel($workspaceId, null, $clientId, $from, $until),
            sources: $this->leads->bySource($workspaceId, null, $clientId, $from, $until),
            campaigns: $this->leads->byCampaign($workspaceId, null, $clientId, $from, $until),
            response: $this->leads->responseTime($workspaceId, null, $clientId, $from, $until),
        );

        $caveats[] = 'These are the leads recorded for this client, not the ad platforms\' own lead counts. A lead is not '
            .'yet a qualified lead, and a qualified lead is not a sale.';
        $caveats[] = 'Where a lead stands keeps changing after the period closes, so the qualified and won counts for '
            .'recent weeks can still rise.';

        foreach ($figures->sources as $row) {
            if (! $row->isMeasured() && $row->total > 0) {
                $caveats[] = 'Some leads were entered by hand or imported. They are counted, but nothing says where they came from.';
                break;
            }
        }

        if ($figures->response->unanswered > 0) {
            $caveats[] = sprintf(
                '%d leads have no recorded first contact. They are left out of the response times, not counted as slow.',
                $figures->response->unanswered,
            );
        }

        return new RenderedSection(
            kind: $section->kind,
            title: $title,
            metrics: [],
            source: MetricSource::Synced,
            isEmpty: $figures->isEmpty(),
            caveats: array_values(array_unique($caveats)),
            lead: $figures,
        );
    }

    /**
     * A creative section: one view of how the report's creatives performed.
     *
     * Read from the creative layer the Creatives page reads, inside the scope the report was written with (its client,
     * platforms and campaigns, plus whatever a visitor narrowed to), so a number here is the number there. Nothing is
     * computed in this method but the format mix, which is a sum over rows already read — and which is a share of money
     * only when the creatives share a currency.
     *
     * @param  list<string>  $caveats
     */
    private function creative(
        string $workspaceId,
        ReportDefinition $definition,
        SectionDefinition $section,
        DateRange $period,
        string $title,
        array $caveats,
        ?ReportNarrowing $narrowing = null,
    ): RenderedSection {
        $view = $section->creativeView ?? CreativeView::Top;
        $limit = max(1, min($section->rowLimit ?? $view->defaultLimit(), 100));
        $chosen = $narrowing === null ? [] : $narrowing->creativeIds;

        $read = $this->creatives->read(
            $workspaceId,
            $period,
            $definition->filters,
            'asset',
            $narrowing?->format,
            $view === CreativeView::Top ? 'top' : null,
            /* A visitor's creative filter is applied after the read, so the read must reach every creative it may name. */
            $chosen !== [] || in_array($view, [CreativeView::Refresh, CreativeView::FormatMix, CreativeView::PlatformMix], true) ? 100 : $limit,
        );

        $rows = $read->rows;

        if ($chosen !== []) {
            $rows = array_slice(array_values(array_filter(
                $rows,
                static fn (CreativeRow $row): bool => in_array($row->info->key, $chosen, true),
            )), 0, $limit);
        }

        if ($view === CreativeView::Refresh) {
            $rows = array_slice(array_values(array_filter(
                $rows,
                static fn (CreativeRow $row): bool => in_array($row->assessment->verdict?->value, ['fatiguing', 'weak'], true),
            )), 0, $limit);
        }

        $currencies = array_values(array_unique(array_map(
            static fn (CreativeRow $row): string => $row->metrics->currency ?? '',
            $rows,
        )));
        $mixIsSpend = count($currencies) <= 1;
        $mix = [];

        if ($view === CreativeView::FormatMix) {
            $groups = [];

            foreach ($rows as $row) {
                $key = $row->info->format;
                $groups[$key] ??= [
                    'key' => $key,
                    'label' => CreativeFormat::tryFrom($key)?->label() ?? $key,
                    'value' => 0.0,
                ];
                $groups[$key]['value'] += $mixIsSpend ? (float) ($row->metrics->get(Metric::Spend)->value ?? 0) : 1.0;
            }

            $mix = array_values($groups);
            usort($mix, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        }

        if ($view === CreativeView::Top || $view === CreativeView::Refresh) {
            $caveats[] = 'Judged only on creatives seen at least 1,000 times in this period and the one before, and only '
                .'when four or more are being compared. Fewer than that is left unrated rather than rated down.';
        }

        if ($view === CreativeView::FormatMix && ! $mixIsSpend) {
            $caveats[] = 'These creatives are paid for in more than one currency, so the mix counts creatives rather '
                .'than sharing out money.';
        }

        /*
         * The gallery carries the scope's own totals and its days, from the analytics layer: a hero's figures and a
         * trend's line, read once rather than added up from the rows on screen.
         */
        $summary = $view === CreativeView::Gallery
            ? $this->analytics->summary($workspaceId, $period, $definition->filters, self::CREATIVE_METRICS)
            : null;
        $series = $view === CreativeView::Gallery
            ? $this->analytics->series($workspaceId, $period, $definition->filters, self::CREATIVE_TREND)
            : [];
        $platformMix = $view === CreativeView::PlatformMix ? $this->platformMix($rows) : [];

        $breakdown = [];

        foreach ($rows as $index => $row) {
            /* The position stands in for the key: a creative's own identifier is not part of what a report says. */
            $breakdown[] = new BreakdownRow(Dimension::Creative, 'creative-'.($index + 1), $row->info->name, $row->metrics);
        }

        $sources = array_map(static fn (CreativeRow $row): MetricSource => $row->metrics->source, $rows);

        return new RenderedSection(
            kind: $section->kind,
            title: $title,
            metrics: [Metric::Spend, Metric::Impressions, Metric::Ctr, Metric::Conversions, Metric::CostPerConversion],
            source: $sources === [] ? MetricSource::Synced : MetricSource::weakest($sources),
            isEmpty: $rows === [],
            caveats: array_values(array_unique($caveats)),
            rows: $breakdown,
            currency: count($currencies) === 1 && $currencies[0] !== '' ? $currencies[0] : null,
            totals: $summary,
            series: $series,
            creative: new CreativeSection($view, $rows, $mix, $mixIsSpend, $read->total, $summary, $series, $platformMix),
        );
    }

    /**
     * Each platform's creatives, summed over the rows already read — a count, spend, results, and the click-through
     * and cost per result those sums give. A platform whose creatives are paid in two currencies keeps its count and
     * says nothing about money.
     *
     * @param  list<CreativeRow>  $rows
     * @return list<array{key: string, label: string, creatives: int, spend: float, results: float, ctr: ?float, cpa: ?float, currency: ?string}>
     */
    private function platformMix(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row->info->provider;
            $groups[$key] ??= ['key' => $key, 'label' => Provider::tryFrom($key)?->label() ?? $key, 'creatives' => 0, 'spend' => 0.0, 'results' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0, 'currencies' => []];
            $groups[$key]['creatives']++;
            $groups[$key]['spend'] += (float) ($row->metrics->get(Metric::Spend)->value ?? 0);
            $groups[$key]['results'] += (float) ($row->metrics->get(Metric::Conversions)->value ?? 0);
            $groups[$key]['impressions'] += (float) ($row->metrics->get(Metric::Impressions)->value ?? 0);
            $groups[$key]['clicks'] += (float) ($row->metrics->get(Metric::Clicks)->value ?? 0);
            $groups[$key]['currencies'][$row->metrics->currency ?? ''] = true;
        }

        $out = [];

        foreach ($groups as $group) {
            $oneCurrency = count($group['currencies']) === 1;
            $currency = $oneCurrency ? (string) array_key_first($group['currencies']) : null;

            $out[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'creatives' => $group['creatives'],
                'spend' => $oneCurrency ? $group['spend'] : 0.0,
                'results' => $group['results'],
                'ctr' => $group['impressions'] > 0 ? round($group['clicks'] / $group['impressions'] * 100, 2) : null,
                'cpa' => $oneCurrency && $group['results'] > 0 ? round($group['spend'] / $group['results'], 2) : null,
                'currency' => $currency === '' ? null : $currency,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $out;
    }

    /**
     * What the client's stores sold, according to the one authority the section names.
     *
     * Read for the report's own client and never across clients: `ReportDefinition` refuses a store section without
     * one, and the query is narrowed by it here as well, so a definition edited around the check still reads one
     * client's orders. No comparison period — a store's earlier month is a different question with its own caveat.
     *
     * @param  list<string>  $caveats
     */
    private function store(
        string $workspaceId,
        ReportDefinition $definition,
        SectionDefinition $section,
        DateRange $period,
        ?DateRange $comparisonPeriod,
        string $title,
        array $caveats,
        ?string $storeId = null,
    ): RenderedSection {
        $source = $section->revenueSource;

        if ($source === null || $definition->clientId === null) {
            // Unreachable through the use cases, which validate first. Kept honest rather than assumed.
            return new RenderedSection(
                kind: $section->kind,
                title: $title,
                metrics: [],
                source: MetricSource::Synced,
                isEmpty: true,
                caveats: $caveats,
            );
        }

        $query = new StoreQuery(
            workspaceId: $workspaceId,
            revenueSource: $source,
            from: $period->start,
            until: $period->end,
            visibleProjectIds: null,
            clientId: $definition->clientId,
            storeId: $storeId,
            limit: 8,
        );

        $summary = $this->stores->summary($query);

        $previous = $comparisonPeriod === null ? null : $this->stores->summary(new StoreQuery(
            workspaceId: $workspaceId,
            revenueSource: $source,
            from: $comparisonPeriod->start,
            until: $comparisonPeriod->end,
            visibleProjectIds: null,
            clientId: $definition->clientId,
            storeId: $storeId,
        ));

        $stores = $storeId === null ? $this->stores->byStore($query) : [];
        $currencies = array_unique(array_map(static fn ($row): string => $row->currency, $stores));

        $figures = new StoreFigures(
            $source,
            $summary,
            previous: $previous,
            daily: $this->stores->daily($query),
            /*
             * A comparison needs something to compare and one currency to compare in: shares of a total made of
             * two currencies are not shares of anything.
             */
            stores: count($stores) > 1 && count($currencies) === 1 ? $stores : [],
            products: $this->stores->byProduct(new StoreQuery(
                $workspaceId, $source, $period->start, $period->end, null, $definition->clientId, $storeId, 5,
            )),
            channels: $this->stores->byChannel($query),
            customerMix: $this->stores->customerMix($query),
        );

        if ($source->isModelled()) {
            $caveats[] = 'Attributed revenue is a model\'s output, not a record. It must not be presented to a client '
                .'as actual.';
        }

        if ($summary->pendingOrders > 0) {
            $caveats[] = sprintf(
                '%d orders were placed and not yet paid for. They are not counted above — cash on delivery becomes '
                .'revenue when it is collected.',
                $summary->pendingOrders,
            );
        }

        return new RenderedSection(
            kind: $section->kind,
            title: $title,
            metrics: [],
            source: $summary->source,
            isEmpty: $figures->isEmpty(),
            caveats: array_values(array_unique($caveats)),
            currency: $summary->currency,
            store: $figures,
        );
    }

    /**
     * What this section cannot say.
     *
     * Assembled per section rather than once per report, because a caveat attached to the whole document is one
     * nobody reads next to the figure it is about.
     *
     * @return list<string>
     */
    private function caveats(ComparisonMode $comparison, SectionDefinition $section): array
    {
        $caveats = [];

        if ($section->kind->supportsComparison()) {
            $caveat = $comparison->caveat();

            if ($caveat !== null) {
                $caveats[] = $caveat;
            }
        }

        foreach ($section->metrics as $metric) {
            if ($metric === Metric::Reach && $section->kind !== SectionKind::Breakdown) {
                /*
                 * Reach is legitimate in a scorecard or a trend and still needs saying: it is deduplicated people
                 * over the whole period, so it is not the sum of the days and a reader comparing it to one is
                 * comparing two different things.
                 */
                $caveats[] = 'Reach counts people, deduplicated over the whole period. It is not the sum of the '
                    .'days, and two periods\' reach cannot be added.';
            }
        }

        return array_values(array_unique($caveats));
    }

    private function defaultTitle(SectionDefinition $section, int $index): string
    {
        $metric = $section->metrics[0] ?? null;

        if ($section->kind === SectionKind::Lead) {
            return 'Leads';
        }

        if ($section->kind === SectionKind::Creative) {
            return $section->creativeView?->label() ?? $section->kind->label();
        }

        if ($section->kind === SectionKind::Store) {
            return sprintf('Store figures — %s', $section->revenueSource?->label() ?? 'unnamed source');
        }

        if ($metric === null) {
            return $section->kind->label();
        }

        return match ($section->kind) {
            SectionKind::Scorecard => $section->kind->label(),
            SectionKind::Trend => sprintf('%s over time', $metric->label()),
            SectionKind::Breakdown, SectionKind::Table => $section->dimension === null
                ? $section->kind->label()
                : sprintf('%s by %s', $metric->label(), mb_strtolower($section->dimension->label())),
        };
    }

    private function currencyOf(?MetricSet $set): ?string
    {
        return $set?->currency;
    }
}
