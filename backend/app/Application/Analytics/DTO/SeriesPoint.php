<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use DateTimeImmutable;

/** One day in a time series. */
final readonly class SeriesPoint
{
    public function __construct(
        public DateTimeImmutable $date,
        public MetricSet $metrics,
    ) {}
}
