<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * The width of one point on a trend. Weeks begin on Monday (ISO 8601) — said here and in the response, because a week that starts on a
 * different day is a different set of orders, and a reader comparing it with a platform's own report needs to know which.
 */
enum TrendGrain: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
