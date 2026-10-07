<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\DTO\RenderedSection;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Metric;
use App\Domain\Reporting\Comparability;
use App\Domain\Reporting\ComparisonMode;
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
    public function __construct(
        private AnalyticsReadModel $analytics,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $workspaceId,
        SavedReport $report,
        ?ReportAudience $as = null,
    ): RenderedReport {
        $now = $this->clock->now();
        $definition = $report->definition;
        $audience = $as ?? $definition->audience;

        $period = $definition->window->resolve(
            $now,
            $definition->customFrom,
            $definition->customUntil,
        );

        $comparisonPeriod = $definition->comparison->resolve($period);

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

    private function section(
        string $workspaceId,
        ReportDefinition $definition,
        SectionDefinition $section,
        DateRange $period,
        ?DateRange $comparisonPeriod,
        int $index,
    ): RenderedSection {
        $title = $section->title ?? $this->defaultTitle($section, $index);
        $caveats = $this->caveats($definition->comparison, $section);

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
