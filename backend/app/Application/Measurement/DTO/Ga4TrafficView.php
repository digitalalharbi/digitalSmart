<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * Stored GA4 acquisition traffic for a period: totals, a comparison, a daily trend and a grouped table.
 *
 * Users are deliberately absent. A user active on two days, or arriving through two channels, is one person, so a
 * sum of per-row users overstates reach; the figure only exists at a grain this table does not hold.
 *
 * @phpstan-type Figures array{sessions: ?int, engaged_sessions: ?int, page_views: ?int, engagement_seconds: ?int, engagement_rate: ?float, engagement_seconds_per_session: ?float}
 */
final readonly class Ga4TrafficView
{
    /**
     * @param  Figures  $totals
     * @param  Figures  $previous
     * @param  array<string, ?float>  $change
     * @param  list<array<string, mixed>>  $trend
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $quality
     * @param  list<array<string, mixed>>  $sources
     */
    public function __construct(
        public string $from,
        public string $until,
        public string $previousFrom,
        public string $previousUntil,
        public string $group,
        public array $totals,
        public array $previous,
        public array $change,
        public array $trend,
        public array $rows,
        public int $rowCount,
        public int $page,
        public int $perPage,
        public array $quality,
        public array $sources,
    ) {}
}
