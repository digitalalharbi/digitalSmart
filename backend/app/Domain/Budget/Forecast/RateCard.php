<?php

declare(strict_types=1);

namespace App\Domain\Budget\Forecast;

/**
 * The rates a forecast runs on, and where they came from.
 *
 * Every field is nullable, deliberately. A workspace that has never run a video
 * campaign has no view-through rate; a client with no conversion tracking has no
 * conversion rate. The forecast then says what it cannot work out instead of filling the
 * gap with a number from somewhere else.
 *
 * `low` and `high` are the 25th and 75th percentile of the *daily* rate across the
 * observed days — a real statistic about the account's own variation, not a ±20% band
 * invented to look like a range.
 */
final readonly class RateCard
{
    public function __construct(
        /** Cost per thousand impressions, in minor units. */
        public ?int $cpmMinor = null,
        public ?int $cpmLowMinor = null,
        public ?int $cpmHighMinor = null,
        /** Cost per click, in minor units. */
        public ?int $cpcMinor = null,
        public ?int $cpcLowMinor = null,
        public ?int $cpcHighMinor = null,
        /** Clicks per impression. */
        public ?float $ctr = null,
        /** Conversions per click. */
        public ?float $cvr = null,
        public ?float $cvrLow = null,
        public ?float $cvrHigh = null,
        /** Cost per conversion, in minor units. */
        public ?int $cpaMinor = null,
    ) {}

    /** Whether anything can be forecast from impressions and spend. */
    public function canForecastImpressions(): bool
    {
        return $this->cpmMinor !== null && $this->cpmMinor > 0;
    }

    public function canForecastClicks(): bool
    {
        return $this->cpcMinor !== null && $this->cpcMinor > 0;
    }

    public function canForecastConversions(): bool
    {
        return $this->canForecastClicks() && $this->cvr !== null && $this->cvr > 0;
    }

    /**
     * The spread of the daily cost per click, as a share of the median.
     *
     * Used for confidence. Null when there is no band to measure, which is itself
     * information: one day's rate has no spread.
     */
    public function cpcSpread(): ?float
    {
        if ($this->cpcMinor === null || $this->cpcMinor <= 0) {
            return null;
        }

        if ($this->cpcLowMinor === null || $this->cpcHighMinor === null) {
            return null;
        }

        return ($this->cpcHighMinor - $this->cpcLowMinor) / (2 * $this->cpcMinor);
    }
}
