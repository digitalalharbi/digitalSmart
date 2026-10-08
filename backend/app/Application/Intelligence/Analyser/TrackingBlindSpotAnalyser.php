<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Analyser;

use App\Application\Intelligence\Contract\Analyser;
use App\Application\Intelligence\DTO\AnalysisContext;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;

/**
 * Whether the conversion figures mean anything.
 *
 * The analyser that exists because of the worst failure this product could enable: an agency optimising towards a
 * conversion count that stopped being measured. Every other finding is about performance; this one is about
 * whether performance can be read at all, which is why a critical measurement problem outranks a doubling of cost
 * per click.
 *
 * It asks two questions and refuses to answer a third.
 *
 * **Are conversions being reported while measurement is broken?** Then the figure is a floor and not a count, and
 * nobody should be optimising towards it.
 *
 * **Is measurement broken while no conversions are reported at all?** Then the zero is the tracking rather than
 * the market, and the obvious conclusion — the campaigns are not working — is the wrong one.
 *
 * **It never claims the reverse.** Healthy measurement does not make a conversion figure correct; it makes it
 * worth reading. The distinction matters because the first is a claim about the client's whole funnel and this
 * product can see one end of it.
 */
final readonly class TrackingBlindSpotAnalyser implements Analyser
{
    public function code(): string
    {
        return 'tracking_blind_spot';
    }

    public function requires(): Permission
    {
        return Permission::TrackingView;
    }

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array
    {
        $health = $context->tracking;

        if ($health === null) {
            /* The reader may not see measurement health, so nothing is said about it. */
            return [];
        }

        /* The view already counts these, with the same definition the tracking surface reports. */
        $criticalCount = $health->criticalCount();

        if ($criticalCount === 0) {
            return [];
        }

        $conversions = $context->value(Metric::Conversions);
        $source = $context->totals->source;

        $reporting = $conversions !== null && $conversions > 0.0;

        return [new Finding(
            code: $this->code(),
            severity: FindingSeverity::Serious,
            subject: 'Measurement',
            headline: $reporting
                ? 'Conversions are being reported while measurement has a critical problem.'
                : 'No conversions were recorded, and measurement has a critical problem.',
            detail: $reporting
                ? 'The figure is a floor rather than a count: some conversions are being measured and an unknown '
                    .'number are not. Optimising towards it moves budget towards whatever is still being '
                    .'reported.'
                : 'The zero is more likely to be the tracking than the market. The obvious conclusion — that the '
                    .'campaigns are not working — is the one this finding exists to prevent.',
            evidence: [
                new Evidence(
                    metric: Metric::Conversions->value,
                    label: $context->label(Metric::Conversions),
                    value: $conversions,
                    comparedWith: $context->previous(Metric::Conversions),
                    period: $context->periodInWords(),
                    source: $source,
                    readFrom: 'analytics totals',
                    reason: $conversions === null ? 'No conversion metric was returned for this period.' : null,
                ),
                new Evidence(
                    metric: 'tracking_critical_issues',
                    label: 'Critical measurement problems',
                    value: (float) $criticalCount,
                    comparedWith: null,
                    period: sprintf('the last %d days', $health->windowDays),
                    /*
                     * The tracking surface reports its own evidence rather than a metric source, so the figure is
                     * recorded by a person's configuration and the product's own observation. `Manual` is the
                     * honest label: it is a count of findings, not a provider's number.
                     */
                    source: MetricSource::Manual,
                    readFrom: 'tracking health',
                ),
            ],
            confidence: Confidence::of($source, Confidence::DENOMINATOR_FLOOR, true),
            recommendation: 'Clear the critical measurement problems before reading the conversion figures, and '
                .'before anybody reports them to the client. The Tracking Center lists them with the evidence '
                .'behind each.',
        )];
    }
}
