<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Analyser;

use App\Application\Intelligence\Contract\Analyser;
use App\Application\Intelligence\DTO\AnalysisContext;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;

/**
 * What the account is paying per click and per conversion, and whether that moved.
 *
 * The analyser most likely to produce a confident lie, so it is the one with the most refusals in it.
 *
 * **It will not state a rate without a denominator worth stating it from.** A cost per conversion that moved from
 * 40 to 90 is a different fact when it rests on four conversions than on four hundred, and the first is noise with
 * a currency symbol on it. Below the floor it emits a finding saying so, rather than nothing: a reader who sees
 * no finding about efficiency assumes efficiency was fine.
 *
 * **It never computes a rate itself.** `cpc` and `cost_per_conversion` are derived by the analytics layer from the
 * same totals every other surface uses, with the same rules about missing inputs. An analyser with its own
 * division would be a second answer to "what did a click cost".
 *
 * **The direction is the metric's, not the analyser's.** Costs falling is good and spend falling is not, and
 * `Metric::lowerIsBetter()` already holds that — so this says "worse" and "better" rather than "up" and "down",
 * which is the sentence a reader needs.
 */
final readonly class EfficiencyDriftAnalyser implements Analyser
{
    /** Below this, a move is the ordinary week-to-week wobble of an auction. */
    private const float MATERIAL_PERCENT = 20.0;

    /** Above this, something changed rather than drifted. */
    private const float SERIOUS_PERCENT = 50.0;

    public function code(): string
    {
        return 'efficiency_drift';
    }

    public function requires(): Permission
    {
        return Permission::AnalyticsView;
    }

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array
    {
        $findings = [];

        foreach ([[Metric::Cpc, Metric::Clicks], [Metric::CostPerConversion, Metric::Conversions]] as [$rate, $denominator]) {
            $finding = $this->examine($context, $rate, $denominator);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function examine(AnalysisContext $context, Metric $rate, Metric $denominator): ?Finding
    {
        $now = $context->value($rate);
        $then = $context->previous($rate);
        $change = $context->changePercent($rate);
        $events = $context->value($denominator);
        /*
         * The set's own source, not the individual figure's. `MetricSet` carries one state for
         * everything in it precisely so that a reader gets one badge rather than seven, and reaching past that
         * here would be a second answer to a question the DTO has already answered.
         */
        $source = $context->totals->source;

        if ($now === null || $then === null || $change === null) {
            // Nothing to compare. Silent: `FlatPeriodAnalyser` speaks for a period with nothing in it.
            return null;
        }

        $confidence = Confidence::of($source, (int) ($events ?? 0), true);

        $evidence = [
            new Evidence(
                metric: $rate->value,
                label: $context->label($rate),
                value: $now,
                comparedWith: $then,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            ),
            /*
             * The change as evidence in its own right, and not a number the prose may derive. Everything a
             * narrative is allowed to say has to exist as a figure the product computed — see `NarrativeGuard`.
             */
            new Evidence(
                metric: $rate->value.'_change_percent',
                label: sprintf('Change in %s', mb_strtolower($context->label($rate))),
                value: $change,
                comparedWith: null,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            ),
            new Evidence(
                metric: $denominator->value,
                label: $context->label($denominator),
                value: $events,
                comparedWith: $context->previous($denominator),
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            ),
        ];

        if ($confidence === Confidence::Indicative && ($events ?? 0) < Confidence::DENOMINATOR_FLOOR) {
            /*
             * Said rather than skipped. A reader who sees nothing about efficiency concludes efficiency was fine,
             * and the honest answer is that there was not enough delivery to tell.
             */
            return new Finding(
                code: $this->code().'.'.$rate->value.'.insufficient',
                severity: FindingSeverity::Insufficient,
                subject: 'The account',
                headline: sprintf(
                    'Not enough delivery to say whether %s moved.',
                    mb_strtolower($context->label($rate)),
                ),
                detail: sprintf(
                    'The period recorded fewer %s than this product will quote a rate from, so a change in '
                    .'%s would describe chance rather than performance.',
                    mb_strtolower($context->label($denominator)),
                    mb_strtolower($context->label($rate)),
                ),
                evidence: $evidence,
                confidence: $confidence,
            );
        }

        if (abs($change) < self::MATERIAL_PERCENT) {
            return null;
        }

        // The metric's own direction. Costs falling is good; this analyser does not decide that.
        $isWorse = $rate->lowerIsBetter() ? $change > 0 : $change < 0;

        $severity = abs($change) >= self::SERIOUS_PERCENT && $isWorse
            ? FindingSeverity::Serious
            : ($isWorse ? FindingSeverity::Warning : FindingSeverity::Context);

        return new Finding(
            code: $this->code().'.'.$rate->value,
            severity: $severity,
            subject: 'The account',
            headline: sprintf(
                '%s is %s than the period before.',
                $context->label($rate),
                $isWorse ? 'worse' : 'better',
            ),
            detail: $isWorse
                ? 'A move of this size is usually either a change in what is being bid on or a change in what is '
                    .'being measured. Both are worth checking before the next flight.'
                : 'Worth knowing rather than worth acting on: an improvement nobody made deliberately tends to '
                    .'be an auction moving rather than a decision working.',
            evidence: $evidence,
            confidence: $confidence,
            recommendation: $isWorse && $confidence->supportsRecommendation()
                ? 'Compare the delivery by platform and by campaign before changing a bid. A rate that moved '
                    .'because the mix moved is not a rate that moved.'
                : null,
        );
    }
}
