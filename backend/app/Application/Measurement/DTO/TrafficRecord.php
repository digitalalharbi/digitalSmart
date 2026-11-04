<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * One day of one acquisition combination on a property: where sessions came from and what they did.
 *
 * Every figure is nullable and null means the property did not report that metric — never zero. A property whose
 * metadata lacks `newUsers` has no new-user figure, and writing 0 would read as "nobody was new".
 *
 * Users are not summed across days or channels anywhere in this product: a user active on two days is one person, so
 * `totalUsers` and `newUsers` are stored per row and only ever shown for the row's own grain.
 */
final readonly class TrafficRecord
{
    public function __construct(
        public string $date,
        public string $channelGroup,
        public string $source,
        public string $medium,
        public ?int $sessions,
        public ?int $totalUsers,
        public ?int $newUsers,
        public ?int $engagedSessions,
        /** Whole seconds of user engagement, summed over the row's users. */
        public ?int $engagementSeconds,
        public ?int $pageViews,
    ) {}
}
