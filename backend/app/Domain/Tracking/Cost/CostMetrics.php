<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Cost;

/**
 * Cost metrics for one link: spend divided by a count of something the link brought.
 *
 * Every metric is `{minor, currency, reason}`: a figure, or null with the reason there is none. Missing spend is not
 * zero, a missing count is not zero, and a count of zero has no cost per unit — dividing by it would invent infinity or a
 * flattering nothing. ROAS is the one ratio, and only exists when spend and revenue are in one currency.
 */
final class CostMetrics
{
    /** @var array<string, string> metric => the count it divides by, for the reason text */
    public const array DENOMINATORS = [
        'cpc' => 'tracked clicks',
        'cost_per_landing_view' => 'landing views',
        'cost_per_form_start' => 'form starts',
        'cpl' => 'leads',
        'cost_per_contacted_lead' => 'contacted leads',
        'cpql' => 'qualified leads',
        'cost_per_appointment' => 'appointments',
        'cost_per_opportunity' => 'opportunities',
        'cac' => 'sales',
    ];

    /**
     * @param  array<string, ?int>  $counts  metric => its denominator; null means the count is not reported
     * @return array<string, array{minor: ?int, currency: ?string, reason: ?string}>
     */
    public static function compute(LinkSpend $spend, array $counts): array
    {
        $out = [];

        foreach (self::DENOMINATORS as $metric => $what) {
            $out[$metric] = self::one($spend, $counts[$metric] ?? null, $what);
        }

        return $out;
    }

    /**
     * Revenue over spend, never a blend of sources: one call per revenue source.
     *
     * @return array{value: ?float, reason: ?string}
     */
    public static function roas(LinkSpend $spend, ?int $revenueMinor, ?string $revenueCurrency): array
    {
        if (! $spend->basis->hasSpend() || $spend->minor === null) {
            return ['value' => null, 'reason' => $spend->reason ?? 'No spend can be credited to this link.'];
        }

        if ($revenueMinor === null || $revenueCurrency === null) {
            return ['value' => null, 'reason' => 'No revenue is recorded from this source.'];
        }

        if ($revenueCurrency !== $spend->currency) {
            return ['value' => null, 'reason' => 'Spend and revenue are in different currencies and are not converted.'];
        }

        if ($spend->minor === 0) {
            return ['value' => null, 'reason' => 'Spend is zero, so a return on it is undefined.'];
        }

        return ['value' => round($revenueMinor / $spend->minor, 2), 'reason' => null];
    }

    /** @return array{minor: ?int, currency: ?string, reason: ?string} */
    private static function one(LinkSpend $spend, ?int $count, string $what): array
    {
        if (! $spend->basis->hasSpend() || $spend->minor === null) {
            return ['minor' => null, 'currency' => null, 'reason' => $spend->reason ?? 'No spend can be credited to this link.'];
        }

        if ($count === null) {
            return ['minor' => null, 'currency' => null, 'reason' => sprintf('The number of %s is not reported.', $what)];
        }

        if ($count === 0) {
            return ['minor' => null, 'currency' => null, 'reason' => sprintf('No %s yet, so there is no cost per unit.', $what)];
        }

        return ['minor' => intdiv($spend->minor + intdiv($count, 2), $count), 'currency' => $spend->currency, 'reason' => null];
    }
}
