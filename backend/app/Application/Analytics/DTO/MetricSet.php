<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Analytics\Metric;

/**
 * A set of figures that belong together — a scorecard, a row, a point in a series.
 *
 * Carries one source state for the whole set, because that is what the interface
 * labels: a reader does not want seven badges on one card, and the weakest input
 * is what the set as a whole can be trusted to.
 */
final readonly class MetricSet
{
    /** @param array<string, MetricValue> $values keyed by Metric::value */
    public function __construct(
        public array $values,
        public MetricSource $source,
        public ?string $currency = null,
        /** How many stored rows the set was built from. Zero means genuinely no data. */
        public int $rowCount = 0,
    ) {}

    public function get(Metric $metric): ?MetricValue
    {
        return $this->values[$metric->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rowCount === 0;
    }

    /** Whether the interface must visibly mark this set. */
    public function requiresLabel(): bool
    {
        return $this->source->requiresLabel();
    }
}
