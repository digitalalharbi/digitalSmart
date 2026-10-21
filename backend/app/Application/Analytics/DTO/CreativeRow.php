<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Analytics\CreativeAssessment;

/**
 * One creative — or one ad — with how it performed and what can be said of it.
 *
 * `metrics` is the analytics layer's own set for the row, so a figure here is the figure the Analytics page would show for
 * the same selection, with the same source label and the same "missing, not zero". `trend` is the same layer's daily series.
 * `assessment` is the rule-based verdict and its evidence, or none.
 */
final readonly class CreativeRow
{
    /** @param list<SeriesPoint> $trend */
    public function __construct(
        public CreativeInfo $info,
        public MetricSet $metrics,
        public ?MetricSet $previous,
        public array $trend,
        public CreativeAssessment $assessment,
    ) {}
}
