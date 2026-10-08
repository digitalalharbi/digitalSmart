<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Analyser;

use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Intelligence\Contract\Analyser;
use App\Application\Intelligence\DTO\AnalysisContext;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Metric;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;

/**
 * Where the money went, when most of it went to one place.
 *
 * Concentration is not a problem on its own — a client with one good channel should be spending there — so this
 * reports it as **context** rather than as a warning, and raises it only when the concentrated platform is also
 * the expensive one.
 *
 * And it says what it cannot know. Comparing cost per click across platforms is a legitimate comparison of one
 * defined metric, and it is also a comparison of two different auctions selling two different kinds of attention.
 * A search click and a feed click are not the same click. So the finding is phrased as a question for somebody
 * who knows the plan, and the detail says so — rather than recommending a shift of budget this product is in no
 * position to recommend.
 */
final readonly class SpendConcentrationAnalyser implements Analyser
{
    /** Above this share of the period's spend, one platform is carrying the account. */
    private const float CONCENTRATED_SHARE = 0.6;

    /** How much worse the concentrated platform's cost per click has to be before this is worth raising. */
    private const float WORSE_BY = 1.25;

    public function code(): string
    {
        return 'spend_concentration';
    }

    public function requires(): Permission
    {
        return Permission::AnalyticsView;
    }

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array
    {
        if (count($context->byProvider) < 2) {
            /* One platform is not a concentration; it is a plan. */
            return [];
        }

        $total = 0.0;
        $leader = null;
        $leaderSpend = 0.0;

        foreach ($context->byProvider as $row) {
            $spend = $this->metric($row, Metric::Spend);

            if ($spend === null) {
                continue;
            }

            $total += $spend;

            if ($spend > $leaderSpend) {
                $leader = $row;
                $leaderSpend = $spend;
            }
        }

        if ($leader === null || $total <= 0.0) {
            return [];
        }

        $share = $leaderSpend / $total;

        if ($share < self::CONCENTRATED_SHARE) {
            return [];
        }

        $leaderCpc = $this->metric($leader, Metric::Cpc);
        $restCpc = $this->costPerClickOfTheRest($context->byProvider, $leader->key);
        $clicks = $this->metric($leader, Metric::Clicks);
        $source = $leader->metrics->source;

        $isExpensive = $leaderCpc !== null
            && $restCpc !== null
            && $restCpc > 0.0
            && $leaderCpc >= $restCpc * self::WORSE_BY;

        $evidence = [
            new Evidence(
                metric: 'spend_share_percent',
                label: sprintf('%s share of spend', $leader->label),
                value: round($share * 100, 1),
                comparedWith: null,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics by platform',
            ),
            new Evidence(
                metric: Metric::Spend->value,
                label: sprintf('%s spend', $leader->label),
                value: $leaderSpend,
                comparedWith: null,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics by platform',
            ),
        ];

        if ($leaderCpc !== null && $restCpc !== null) {
            $evidence[] = new Evidence(
                metric: Metric::Cpc->value,
                label: sprintf('%s cost per click', $leader->label),
                value: $leaderCpc,
                /*
                 * The other platforms' cost per click sits in `comparedWith` rather than in a sentence, so the
                 * narrative may state it and the guard can check it.
                 */
                comparedWith: $restCpc,
                period: $context->periodInWords(),
                source: $source,
                readFrom: 'analytics by platform',
            );
        }

        return [new Finding(
            code: $this->code().'.'.$leader->key,
            severity: $isExpensive ? FindingSeverity::Warning : FindingSeverity::Context,
            subject: $leader->label,
            headline: $isExpensive
                ? sprintf('%s carries most of the spend and costs more per click than the rest.', $leader->label)
                : sprintf('%s carries most of the spend.', $leader->label),
            detail: $isExpensive
                ? 'Worth a conversation rather than a reallocation. A search click and a feed click are not the '
                    .'same click, so a higher cost per click can be exactly what was intended — this product can '
                    .'see the price and not the plan.'
                : 'Concentration is not a problem by itself. It is here so that a reader knows the account\'s '
                    .'figures are mostly one platform\'s figures.',
            evidence: $evidence,
            confidence: Confidence::of($source, (int) ($clicks ?? 0), true),
            /*
             * No recommendation, even when it is the expensive one. "Move budget" is a decision about a plan this
             * product has not read, and a recommendation somebody follows is worse than a question somebody
             * answers.
             */
        )];
    }

    private function metric(BreakdownRow $row, Metric $metric): ?float
    {
        $value = $row->metrics->get($metric)?->value;

        return $value === null ? null : (float) $value;
    }

    /**
     * Cost per click across every platform except the leader, computed from their totals.
     *
     * Totals rather than an average of the rates: averaging per-platform rates weights a platform with nine
     * clicks the same as one with ninety thousand, which is the arithmetic that makes a small test look like half
     * the account.
     *
     * @param  list<BreakdownRow>  $rows
     */
    private function costPerClickOfTheRest(array $rows, string $leaderKey): ?float
    {
        $spend = 0.0;
        $clicks = 0.0;

        foreach ($rows as $row) {
            if ($row->key === $leaderKey) {
                continue;
            }

            $spend += $this->metric($row, Metric::Spend) ?? 0.0;
            $clicks += $this->metric($row, Metric::Clicks) ?? 0.0;
        }

        return $clicks > 0.0 ? $spend / $clicks : null;
    }
}
