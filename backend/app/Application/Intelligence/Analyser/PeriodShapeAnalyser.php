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
 * What the period was, whether or not anything was wrong with it.
 *
 * The analyser that keeps the list honest. Every other one speaks only when it has something to report, so without
 * this a quiet period produces an empty page — and an empty page says "nothing is wrong" when what it means is
 * "nothing matched". A reader cannot tell the difference, and the difference is everything.
 *
 * It emits exactly one finding, always: what was spent, what it bought, and whether that moved. When the account
 * recorded nothing at all it says **that**, rather than reporting zeroes — a total of zero and an absence of data
 * are different facts, and this product does not conflate them anywhere else either.
 */
final readonly class PeriodShapeAnalyser implements Analyser
{
    public function code(): string
    {
        return 'period_shape';
    }

    public function requires(): Permission
    {
        return Permission::AnalyticsView;
    }

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array
    {
        $spend = $context->value(Metric::Spend);
        $clicks = $context->value(Metric::Clicks);
        $source = $context->totals->source;

        if ($context->totals->isEmpty() || $spend === null) {
            return [new Finding(
                code: $this->code().'.empty',
                severity: FindingSeverity::Insufficient,
                subject: 'The account',
                headline: 'Nothing was recorded for this period.',
                detail: 'No delivery was read for these dates. That is not a total of zero: it is the absence of '
                    .'data, and the two have different causes — a period before the account was connected looks '
                    .'exactly like a period in which nothing ran.',
                evidence: [
                    new Evidence(
                        metric: Metric::Spend->value,
                        label: $context->label(Metric::Spend),
                        value: null,
                        comparedWith: $context->previous(Metric::Spend),
                        period: $context->periodInWords(),
                        source: $source,
                        readFrom: 'analytics totals',
                        reason: 'No rows were returned for this period.',
                    ),
                ],
                confidence: Confidence::Indicative,
            )];
        }

        $change = $context->changePercent(Metric::Spend);

        $evidence = [
            new Evidence(
                metric: Metric::Spend->value,
                label: $context->label(Metric::Spend),
                value: $spend,
                comparedWith: $context->previous(Metric::Spend),
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            ),
            new Evidence(
                metric: Metric::Clicks->value,
                label: $context->label(Metric::Clicks),
                value: $clicks,
                comparedWith: $context->previous(Metric::Clicks),
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            ),
        ];

        if ($change !== null) {
            $evidence[] = new Evidence(
                metric: 'spend_change_percent',
                label: 'Change in spend',
                value: $change,
                comparedWith: null,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics totals',
            );
        }

        return [new Finding(
            code: $this->code(),
            severity: FindingSeverity::Context,
            subject: 'The account',
            headline: $change === null
                ? 'What the period spent, with no comparable period before it.'
                : 'What the period spent, against the period before.',
            detail: $change === null
                ? 'There is nothing to compare this with, so every figure here is a level rather than a '
                    .'direction.'
                : 'The baseline for every change figure in this analysis is the period immediately before this '
                    .'one, of the same length.',
            evidence: $evidence,
            confidence: Confidence::of($source, (int) ($clicks ?? 0), $context->hasBothPeriods(Metric::Spend)),
        )];
    }
}
