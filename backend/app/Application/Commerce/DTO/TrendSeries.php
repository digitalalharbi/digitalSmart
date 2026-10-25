<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Commerce\TrendGrain;

/** Revenue over time at one grain. Each point is dated by the first day of its bucket, in the store's own calendar. */
final readonly class TrendSeries
{
    /** @param list<DailyRevenuePoint> $points */
    public function __construct(public TrendGrain $grain, public array $points) {}
}
