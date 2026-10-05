<?php

declare(strict_types=1);

namespace App\Domain\Advertising\ValueObject;

/**
 * One day's performance for one entity.
 *
 * Every field is nullable, deliberately. No provider exposes every metric:
 * LinkedIn has no deduplicated reach at every level, Google reports conversions
 * as a float with its own attribution, X exposes engagement metrics that Meta
 * does not. A null here means "this platform did not report it", which is a
 * different fact from zero — and the two must never be conflated, because zero
 * purchases is a performance problem while no purchase data is a reporting gap.
 *
 * Derived values (CTR, CPC, CPM, ROAS, CPA) are computed rather than stored, so
 * they cannot drift from the counts they came from, and they return null rather
 * than dividing by zero.
 */
final readonly class MetricSet
{
    public function __construct(
        public ?Money $spend = null,
        public ?int $impressions = null,
        public ?int $reach = null,
        public ?int $clicks = null,
        public ?int $linkClicks = null,
        public ?int $videoViews = null,
        public ?int $engagements = null,
        public ?Money $revenue = null,
        public ?float $conversions = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** Clicks ÷ impressions. Null when there were no impressions to divide by. */
    public function clickThroughRate(): ?float
    {
        if ($this->impressions === null || $this->impressions === 0 || $this->clicks === null) {
            return null;
        }

        return $this->clicks / $this->impressions;
    }

    /** Link CTR — the one that matters for traffic objectives. */
    public function linkClickThroughRate(): ?float
    {
        if ($this->impressions === null || $this->impressions === 0 || $this->linkClicks === null) {
            return null;
        }

        return $this->linkClicks / $this->impressions;
    }

    public function costPerClick(): ?Money
    {
        if ($this->spend === null || $this->clicks === null || $this->clicks === 0) {
            return null;
        }

        return Money::of(intdiv($this->spend->minorUnits, $this->clicks), $this->spend->currency);
    }

    /** Cost per thousand impressions. */
    public function costPerMille(): ?Money
    {
        if ($this->spend === null || $this->impressions === null || $this->impressions === 0) {
            return null;
        }

        return Money::of(
            (int) round($this->spend->minorUnits * 1000 / $this->impressions),
            $this->spend->currency,
        );
    }

    /** Average times each reached person saw an ad. */
    public function frequency(): ?float
    {
        if ($this->impressions === null || $this->reach === null || $this->reach === 0) {
            return null;
        }

        return $this->impressions / $this->reach;
    }

    /**
     * Return on ad spend.
     *
     * Refuses to mix currencies: revenue and spend must be denominated the same,
     * or the ratio is meaningless.
     */
    public function returnOnAdSpend(): ?float
    {
        if ($this->revenue === null || $this->spend === null || $this->spend->isZero()) {
            return null;
        }

        if ($this->revenue->currency !== $this->spend->currency) {
            return null;
        }

        return $this->revenue->minorUnits / $this->spend->minorUnits;
    }

    public function costPerAction(): ?Money
    {
        if ($this->spend === null || $this->conversions === null || $this->conversions <= 0.0) {
            return null;
        }

        return Money::of((int) round($this->spend->minorUnits / $this->conversions), $this->spend->currency);
    }

    /** Conversions ÷ clicks. */
    public function conversionRate(): ?float
    {
        if ($this->conversions === null || $this->clicks === null || $this->clicks === 0) {
            return null;
        }

        return $this->conversions / $this->clicks;
    }

    /** True when the provider returned nothing at all for this day. */
    public function isEmpty(): bool
    {
        return $this->spend === null
            && $this->impressions === null
            && $this->clicks === null
            && $this->conversions === null;
    }
}
