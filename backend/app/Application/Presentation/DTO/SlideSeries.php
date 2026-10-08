<?php

declare(strict_types=1);

namespace App\Application\Presentation\DTO;

/**
 * One line or one set of bars.
 *
 * `points` keeps nulls as nulls. A day with no delivery is a gap, and a renderer that drew it as zero would put a
 * line through the floor of a chart on a slide somebody presents to a client.
 */
final readonly class SlideSeries
{
    /**
     * @param  list<float|null>  $points
     * @param  list<string>  $labels  the formatted value per point, for a table and for a tooltip
     */
    public function __construct(
        public string $name,
        public array $points,
        public array $labels,
    ) {}
}
