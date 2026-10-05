<?php

declare(strict_types=1);

namespace App\Domain\Budget\Forecast;

/**
 * What a budget might buy, or what a target might cost.
 *
 * Both directions produce the same shape, because they are the same arithmetic read
 * from either end — and because a reader comparing "this budget buys about 900 leads"
 * with "900 leads costs about this budget" should not have to learn two screens.
 *
 * Every figure is an estimate with a band, and every figure may be null: a rate the
 * account has never observed produces no projection rather than a plausible number.
 * Nothing here is a guarantee, and the word is never used.
 */
final readonly class Projection
{
    /**
     * @param  list<string>  $assumptions  the rates this was worked out from, in words
     * @param  list<string>  $gaps  what could not be worked out, and why
     */
    private function __construct(
        public ?int $impressions = null,
        public ?int $impressionsLow = null,
        public ?int $impressionsHigh = null,
        public ?int $clicks = null,
        public ?int $clicksLow = null,
        public ?int $clicksHigh = null,
        public ?float $conversions = null,
        public ?float $conversionsLow = null,
        public ?float $conversionsHigh = null,
        /** For the reverse direction: what the target would cost. */
        public ?int $requiredBudgetMinor = null,
        public ?int $requiredBudgetLowMinor = null,
        public ?int $requiredBudgetHighMinor = null,
        public array $assumptions = [],
        /** What could not be worked out, and why. */
        public array $gaps = [],
    ) {}

    /**
     * Budget → results.
     *
     * Impressions come from the CPM and clicks from the CPC, rather than clicks being
     * derived from impressions and a click-through rate. Both routes exist in the data
     * and they disagree slightly; using each metric's own cost keeps every figure
     * traceable to one observed rate instead of a chain of three.
     */
    public static function fromBudget(int $budgetMinor, RateCard $rates): self
    {
        $assumptions = [];
        $gaps = [];

        $impressions = null;
        $impressionsLow = null;
        $impressionsHigh = null;

        if ($rates->canForecastImpressions()) {
            $impressions = self::impressionsFor($budgetMinor, $rates->cpmMinor);
            // A *higher* cost buys *fewer* impressions, so the bands cross over.
            $impressionsLow = self::impressionsFor($budgetMinor, $rates->cpmHighMinor ?? $rates->cpmMinor);
            $impressionsHigh = self::impressionsFor($budgetMinor, $rates->cpmLowMinor ?? $rates->cpmMinor);
            $assumptions[] = 'impressions from the observed cost per thousand impressions';
        } else {
            $gaps[] = 'Impressions: no cost per thousand impressions has been observed for this scope.';
        }

        $clicks = null;
        $clicksLow = null;
        $clicksHigh = null;

        if ($rates->canForecastClicks()) {
            $clicks = intdiv($budgetMinor, (int) $rates->cpcMinor);
            $clicksLow = intdiv($budgetMinor, (int) ($rates->cpcHighMinor ?? $rates->cpcMinor));
            $clicksHigh = intdiv($budgetMinor, (int) ($rates->cpcLowMinor ?? $rates->cpcMinor));
            $assumptions[] = 'clicks from the observed cost per click';
        } else {
            $gaps[] = 'Clicks: no cost per click has been observed for this scope.';
        }

        $conversions = null;
        $conversionsLow = null;
        $conversionsHigh = null;

        if ($clicks !== null && $rates->canForecastConversions()) {
            $conversions = $clicks * (float) $rates->cvr;
            $conversionsLow = ($clicksLow ?? $clicks) * (float) ($rates->cvrLow ?? $rates->cvr);
            $conversionsHigh = ($clicksHigh ?? $clicks) * (float) ($rates->cvrHigh ?? $rates->cvr);
            $assumptions[] = 'conversions from the observed conversion rate';
        } else {
            $gaps[] = 'Conversions: no conversion rate has been observed for this scope. '
                .'Conversion tracking has to be reporting before this can be estimated.';
        }

        return new self(
            impressions: $impressions,
            impressionsLow: $impressionsLow,
            impressionsHigh: $impressionsHigh,
            clicks: $clicks,
            clicksLow: $clicksLow,
            clicksHigh: $clicksHigh,
            conversions: $conversions,
            conversionsLow: $conversionsLow,
            conversionsHigh: $conversionsHigh,
            assumptions: $assumptions,
            gaps: $gaps,
        );
    }

    /**
     * Target results → budget.
     *
     * The band crosses over again: the *cheaper* rate needs the *smaller* budget.
     */
    public static function forTargetConversions(float $target, RateCard $rates): self
    {
        if ($target <= 0) {
            return new self(gaps: ['A target has to be above zero.']);
        }

        if (! $rates->canForecastConversions()) {
            return new self(gaps: [
                'No conversion rate has been observed for this scope, so the budget a conversion target needs cannot be worked out.',
            ]);
        }

        $clicks = (int) ceil($target / (float) $rates->cvr);
        $clicksAtBest = (int) ceil($target / (float) ($rates->cvrHigh ?? $rates->cvr));
        $clicksAtWorst = (int) ceil($target / (float) ($rates->cvrLow ?? $rates->cvr));

        return new self(
            clicks: $clicks,
            clicksLow: $clicksAtBest,
            clicksHigh: $clicksAtWorst,
            conversions: $target,
            requiredBudgetMinor: $clicks * (int) $rates->cpcMinor,
            requiredBudgetLowMinor: $clicksAtBest * (int) ($rates->cpcLowMinor ?? $rates->cpcMinor),
            requiredBudgetHighMinor: $clicksAtWorst * (int) ($rates->cpcHighMinor ?? $rates->cpcMinor),
            assumptions: [
                'clicks needed from the observed conversion rate',
                'budget from the observed cost per click',
            ],
        );
    }

    /** Target clicks → budget. */
    public static function forTargetClicks(int $target, RateCard $rates): self
    {
        if ($target <= 0) {
            return new self(gaps: ['A target has to be above zero.']);
        }

        if (! $rates->canForecastClicks()) {
            return new self(gaps: [
                'No cost per click has been observed for this scope, so the budget a click target needs cannot be worked out.',
            ]);
        }

        return new self(
            clicks: $target,
            conversions: $rates->cvr === null ? null : $target * $rates->cvr,
            requiredBudgetMinor: $target * (int) $rates->cpcMinor,
            requiredBudgetLowMinor: $target * (int) ($rates->cpcLowMinor ?? $rates->cpcMinor),
            requiredBudgetHighMinor: $target * (int) ($rates->cpcHighMinor ?? $rates->cpcMinor),
            assumptions: ['budget from the observed cost per click'],
        );
    }

    /** Target impressions → budget. */
    public static function forTargetImpressions(int $target, RateCard $rates): self
    {
        if ($target <= 0) {
            return new self(gaps: ['A target has to be above zero.']);
        }

        if (! $rates->canForecastImpressions()) {
            return new self(gaps: [
                'No cost per thousand impressions has been observed for this scope, so the budget an impression target needs cannot be worked out.',
            ]);
        }

        $thousands = $target / 1000;

        return new self(
            impressions: $target,
            requiredBudgetMinor: (int) round($thousands * (int) $rates->cpmMinor),
            requiredBudgetLowMinor: (int) round($thousands * (int) ($rates->cpmLowMinor ?? $rates->cpmMinor)),
            requiredBudgetHighMinor: (int) round($thousands * (int) ($rates->cpmHighMinor ?? $rates->cpmMinor)),
            assumptions: ['budget from the observed cost per thousand impressions'],
        );
    }

    private static function impressionsFor(int $budgetMinor, ?int $cpmMinor): ?int
    {
        if ($cpmMinor === null || $cpmMinor <= 0) {
            return null;
        }

        return (int) round($budgetMinor / $cpmMinor * 1000);
    }
}
