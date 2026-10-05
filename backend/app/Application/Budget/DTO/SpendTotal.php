<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

use App\Domain\Advertising\MetricSource;

/**
 * Actual spend for a scope, and whether it may be used at all.
 *
 * A pacing figure is a comparison between a number a client approved and a number the
 * platforms reported, so both sides have to be honest about themselves. Spend that
 * covers two currencies is not a total; spend that came from fixtures is not spend.
 * Both arrive here as a null with a reason rather than as a number.
 */
final readonly class SpendTotal
{
    private function __construct(
        /** Minor units, or null when no total may be stated. */
        public ?int $minorUnits,
        public MetricSource $source,
        public ?string $currency,
        public ?string $unavailableReason = null,
        /** How many rows carried spend, which is what separates "none" from "not reported". */
        public int $rowCount = 0,
    ) {}

    public static function of(int $minorUnits, MetricSource $source, string $currency, int $rowCount): self
    {
        return new self($minorUnits, $source, $currency, null, $rowCount);
    }

    /** Nothing was reported at all. Not a zero: a zero is a measurement. */
    public static function notReported(MetricSource $source = MetricSource::Live): self
    {
        return new self(null, $source, null, 'No spend has been reported for this period yet.', 0);
    }

    public static function unavailable(string $reason, MetricSource $source, int $rowCount = 0): self
    {
        return new self(null, $source, null, $reason, $rowCount);
    }

    public function hasValue(): bool
    {
        return $this->minorUnits !== null;
    }
}
