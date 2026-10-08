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
 * A platform that was spending and has stopped.
 *
 * The one finding in this module that is almost always worth waking somebody for, because the cause is nearly
 * always administrative rather than strategic: a card declined, an account suspended, a campaign paused by
 * somebody who meant to pause a different one. None of those shows up as a bad number — the account just goes
 * quiet, and a weekly report two weeks later is a slow way to find out.
 *
 * **It compares per platform, not in total.** Two platforms moving in opposite directions cancel out in a total,
 * and the one that stopped is exactly the one the total hides.
 *
 * **It needs a previous period worth comparing against.** A platform that spent four riyals last month and
 * nothing this month has not stopped; it was never running. The floor is a monetary one rather than a count,
 * because that is what "was it actually live" means for spend.
 */
final readonly class DeliveryStoppedAnalyser implements Analyser
{
    /**
     * The spend, in minor units, below which a platform was not meaningfully running.
     *
     * Ten units of currency over a whole period. Anything less is a test, a leftover or a rounding of somebody
     * else's figure, and calling its absence an outage would make this finding worthless by crying wolf.
     */
    private const float WAS_RUNNING_MINOR = 1_000.0;

    /** Below this share of its previous spend, a platform has effectively stopped rather than slowed. */
    private const float STOPPED_SHARE = 0.1;

    public function code(): string
    {
        return 'delivery_stopped';
    }

    public function requires(): Permission
    {
        return Permission::AnalyticsView;
    }

    /** @return list<Finding> */
    public function run(AnalysisContext $context): array
    {
        $before = [];

        foreach ($context->byProviderBefore as $row) {
            $before[$row->key] = $row;
        }

        $now = [];

        foreach ($context->byProvider as $row) {
            $now[$row->key] = $row;
        }

        $findings = [];

        foreach ($before as $key => $row) {
            $spentBefore = $this->spend($row);

            if ($spentBefore === null || $spentBefore < self::WAS_RUNNING_MINOR) {
                continue;
            }

            $current = $now[$key] ?? null;
            $spentNow = $current === null ? 0.0 : ($this->spend($current) ?? 0.0);

            if ($spentNow > $spentBefore * self::STOPPED_SHARE) {
                continue;
            }

            $source = $row->metrics->source;

            $findings[] = new Finding(
                code: $this->code().'.'.$key,
                severity: FindingSeverity::Serious,
                subject: $row->label,
                headline: sprintf('%s was spending and has effectively stopped.', $row->label),
                detail: 'A platform going quiet is usually administrative rather than strategic — a declined '
                    .'card, a suspended account, or a campaign somebody paused meaning to pause another. None of '
                    .'those shows up as a bad number anywhere else.',
                evidence: [
                    new Evidence(
                        metric: Metric::Spend->value,
                        label: sprintf('%s spend', $row->label),
                        value: $spentNow,
                        comparedWith: $spentBefore,
                        period: $context->periodInWords(),
                        source: $source,
                        readFrom: 'analytics by platform',
                    ),
                ],
                /*
                 * Spend is a recorded amount rather than a rate, so there is no denominator to be too small: the
                 * figure is as firm as its source and nothing else bears on it.
                 */
                confidence: Confidence::of($source, Confidence::DENOMINATOR_FLOOR, true),
                recommendation: 'Check the platform\'s own billing and account status before changing anything '
                    .'here. This product reads what the platform reports and cannot tell a pause from a '
                    .'suspension.',
            );
        }

        return $findings;
    }

    private function spend(BreakdownRow $row): ?float
    {
        $value = $row->metrics->get(Metric::Spend)?->value;

        return $value === null ? null : (float) $value;
    }
}
