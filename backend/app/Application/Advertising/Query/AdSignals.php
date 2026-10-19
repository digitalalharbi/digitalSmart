<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

/**
 * The deterministic signals on one ad: click-through this week against last, and how often the same people saw it.
 *
 * Arithmetic, not judgement — whether a drop is fatigue is decided by whoever reads these, against a threshold they
 * state. Null wherever the figures to compute it were not reported.
 */
final class AdSignals
{
    /**
     * @param  array<string, mixed>  $ad
     * @return array{ctr_now: float|null, ctr_before: float|null, ctr_change: float|null, frequency: float|null}
     */
    public static function of(array $ad): array
    {
        $ctr = static fn (mixed $clicks, mixed $impressions): ?float => is_numeric($clicks) && is_numeric($impressions) && (float) $impressions > 0
            ? (float) $clicks / (float) $impressions
            : null;

        $now = $ctr($ad['clicks_now'] ?? null, $ad['impressions_now'] ?? null);
        $before = $ctr($ad['clicks_before'] ?? null, $ad['impressions_before'] ?? null);
        $reach = $ad['reach_now'] ?? null;

        return [
            'ctr_now' => $now,
            'ctr_before' => $before,
            'ctr_change' => $now !== null && $before !== null && $before > 0 ? round($now / $before - 1, 4) : null,
            'frequency' => is_numeric($reach) && (float) $reach > 0 && is_numeric($ad['impressions_now'] ?? null)
                ? round((float) $ad['impressions_now'] / (float) $reach, 2)
                : null,
        ];
    }
}
