<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Metric;
use App\Domain\Analytics\MetricFormat;

/**
 * One figure, and everything needed to present it honestly.
 *
 * A bare number is not enough. The interface has to know whether the number
 * exists at all, how far it can be trusted, what currency it is in, and why it is
 * missing when it is — otherwise a gap renders as a zero and a sandbox figure
 * renders as revenue.
 */
final readonly class MetricValue
{
    private function __construct(
        public Metric $metric,
        /** Minor units for money, a plain number otherwise. Null means not reported. */
        public int|float|null $value,
        public MetricSource $source,
        public MetricFormat $format,
        public ?string $currency = null,
        /** Why there is no value, when there is none. */
        public ?string $unavailableReason = null,
        /** The same figure for the comparison period, when one was asked for. */
        public int|float|null $comparisonValue = null,
    ) {}

    public static function of(
        Metric $metric,
        int|float|null $value,
        MetricSource $source,
        ?string $currency = null,
        int|float|null $comparisonValue = null,
    ): self {
        return new self(
            metric: $metric,
            value: $value,
            source: $source,
            format: $metric->format(),
            currency: $metric->isMonetary() ? $currency : null,
            comparisonValue: $comparisonValue,
        );
    }

    /**
     * No figure, and a reason a person can act on.
     *
     * Used where the provider did not report the metric, where currencies cannot
     * be combined, and where a figure would be arithmetically wrong — summed
     * reach, for instance. The reason is shown, not swallowed.
     */
    public static function unavailable(Metric $metric, string $reason, MetricSource $source = MetricSource::Live): self
    {
        return new self(
            metric: $metric,
            value: null,
            source: $source,
            format: $metric->format(),
            unavailableReason: $reason,
        );
    }

    public function hasValue(): bool
    {
        return $this->value !== null;
    }

    /**
     * Change against the comparison period, as a proportion.
     *
     * Null when either side is missing, and null when the earlier figure is zero:
     * "up from nothing" has no percentage, and rendering it as +∞ or +100% is a
     * number someone will quote.
     */
    public function changeRatio(): ?float
    {
        if ($this->value === null || $this->comparisonValue === null) {
            return null;
        }

        if ((float) $this->comparisonValue === 0.0) {
            return null;
        }

        return ($this->value - $this->comparisonValue) / abs((float) $this->comparisonValue);
    }

    /** Whether the change is an improvement, given the metric's direction. */
    public function changeIsGood(): ?bool
    {
        $ratio = $this->changeRatio();

        if ($ratio === null || $ratio === 0.0) {
            return null;
        }

        return $this->metric->lowerIsBetter() ? $ratio < 0 : $ratio > 0;
    }
}
