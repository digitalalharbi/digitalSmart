<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Query;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Intelligence\DTO\ReportProposal;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * A report definition built from what the analysis found.
 *
 * **It proposes and does not save.** The definition comes back as a payload the reporting surface can open
 * pre-filled, and a person decides whether that is the report they want. Saving it here would mean the assistant
 * creating a document with the agency's name on it, and the reporting module is where that decision lives.
 *
 * Three rules shape what it proposes.
 *
 * **It validates the definition before returning it**, through `ReportDefinition::assertValid()` — the same check
 * a hand-built report goes through. A proposal that the reporting module would refuse is worse than no proposal:
 * it costs a reader the time to find out.
 *
 * **It proposes `internal` and never `client_safe`.** A client-safe report is written to be read by the client —
 * in its title, in which metrics are on it and in what is left out — and nothing assembled from an analysis has
 * been read by anybody yet. The audience is a decision somebody makes after reading it.
 *
 * **It carries the metrics the findings actually rest on**, not a standard set. A report proposed from an
 * analysis about cost per conversion that did not contain cost per conversion would be a report about something
 * else.
 */
final readonly class ProposeReport
{
    /** Spend is always on it: a report about performance that omits what it cost is not a report. */
    private const Metric ALWAYS = Metric::Spend;

    public function __construct(
        private Analyse $analyse,
        private AuthorizationGuard $guard,
    ) {}

    public function __invoke(string $clientId, string $clientName, DateRange $period): ReportProposal
    {
        $this->guard->ensure(AssistantMode::Report->requires());

        $analysis = ($this->analyse)($clientId, $clientName, $period);
        $metrics = $this->metricsBehind($analysis->findings);

        $sections = [
            new SectionDefinition(
                kind: SectionKind::Scorecard,
                metrics: array_slice($metrics, 0, SectionKind::Scorecard->metricCap()),
            ),
            new SectionDefinition(
                kind: SectionKind::Trend,
                /* The two that make a trend readable. A trend of six lines is a trend nobody reads. */
                metrics: [Metric::Spend, Metric::Clicks],
            ),
            new SectionDefinition(
                kind: SectionKind::Breakdown,
                /* Additive only: a ranking's rows are summed, and the shape refuses the rest anyway. */
                metrics: array_values(array_filter(
                    array_slice($metrics, 0, SectionKind::Breakdown->metricCap()),
                    static fn (Metric $metric): bool => $metric->isAdditiveAcrossEntities(),
                )),
                dimension: Dimension::Provider,
            ),
        ];

        $definition = new ReportDefinition(
            name: sprintf('%s — %d days to %s', $clientName, $period->lengthInDays(), $period->endDate()),
            /*
             * A rule rather than the dates just analysed. A saved report with frozen dates is one somebody has to
             * remember to edit, and the one they forget is the one that gets sent.
             */
            window: ReportWindow::Last30Days,
            comparison: ComparisonMode::PreviousPeriod,
            audience: ReportAudience::Internal,
            filters: new AnalyticsFilters(clientId: $clientId),
            sections: $sections,
            description: 'Proposed by the assistant from the findings for this period. Read it before sending it.',
            clientId: $clientId,
        );

        /* The same validation a hand-built report goes through. A proposal the module would refuse is worthless. */
        $definition->assertValid();

        return new ReportProposal($analysis, $definition);
    }

    /**
     * The metrics the findings rest on, spend first.
     *
     * Ordered by first appearance so the scorecard reads in the order the analysis did, and de-duplicated
     * because several findings legitimately rest on the same figure.
     *
     * @param  list<Finding>  $findings
     * @return list<Metric>
     */
    private function metricsBehind(array $findings): array
    {
        $metrics = [self::ALWAYS->value => self::ALWAYS];

        foreach ($findings as $finding) {
            foreach ($finding->evidence as $evidence) {
                $metric = $this->metricOf($evidence);

                if ($metric !== null && ! isset($metrics[$metric->value])) {
                    $metrics[$metric->value] = $metric;
                }
            }
        }

        return array_values($metrics);
    }

    /**
     * The metric a piece of evidence is about.
     *
     * Derived figures the analysers invented — `cpc_change_percent`, `spend_share_percent` — are not metrics and
     * have no place in a report definition, so the suffix is stripped and the base metric used. A section asking
     * for `spend_share_percent` would be refused by the reporting module, which is the sort of proposal this
     * use case exists not to make.
     */
    private function metricOf(Evidence $evidence): ?Metric
    {
        $direct = Metric::tryFrom($evidence->metric);

        if ($direct !== null) {
            return $direct;
        }

        foreach (['_change_percent', '_share_percent'] as $suffix) {
            if (str_ends_with($evidence->metric, $suffix)) {
                return Metric::tryFrom(substr($evidence->metric, 0, -strlen($suffix)));
            }
        }

        return null;
    }
}
