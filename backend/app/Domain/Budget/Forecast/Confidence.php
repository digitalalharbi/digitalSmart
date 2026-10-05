<?php

declare(strict_types=1);

namespace App\Domain\Budget\Forecast;

/**
 * How much a forecast is worth.
 *
 * Published with every projection, because a figure derived from four days of one
 * campaign and a figure derived from three months of steady delivery are not the same
 * claim, and an interface that renders them identically is lying by omission.
 *
 * `None` exists so that "we cannot forecast this" is a first-class answer rather than a
 * number with a quiet caveat.
 */
enum Confidence: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** Days of observed delivery below which nothing may be forecast at all. */
    public const int MINIMUM_DAYS = 3;

    /** Days of observed delivery at which a forecast stops being a guess. */
    public const int MEDIUM_DAYS = 7;

    /** Days at which a forecast is as good as this product can make it. */
    public const int HIGH_DAYS = 14;

    /**
     * How wide the observed daily rate may spread before confidence drops.
     *
     * A quarter either side of the median. Wider than that and the "estimate" is really
     * a range covering two different campaigns' behaviour, which is worth saying.
     */
    public const float STABLE_SPREAD = 0.25;

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not enough data',
            self::Low => 'Low confidence',
            self::Medium => 'Medium confidence',
            self::High => 'High confidence',
        };
    }

    /**
     * Confidence from the sample, and nothing else.
     *
     * Not `from()`: an enum already has that, and it means something else.
     *
     * @param  int  $observedDays  days that actually reported delivery
     * @param  float|null  $spread  how far the daily rate ranges around its median
     */
    public static function forSample(int $observedDays, ?float $spread): self
    {
        if ($observedDays < self::MINIMUM_DAYS) {
            return self::None;
        }

        if ($observedDays < self::MEDIUM_DAYS) {
            return self::Low;
        }

        /*
         * An unknown spread cannot be treated as a stable one: a single-rate sample has
         * no spread to measure, and calling that "high" would reward the thinnest data
         * with the most confidence.
         */
        if ($spread === null || $spread > self::STABLE_SPREAD) {
            return self::Medium;
        }

        return $observedDays >= self::HIGH_DAYS ? self::High : self::Medium;
    }

    public function allowsForecast(): bool
    {
        return $this !== self::None;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
