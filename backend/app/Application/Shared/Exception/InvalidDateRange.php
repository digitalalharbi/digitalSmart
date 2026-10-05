<?php

declare(strict_types=1);

namespace App\Application\Shared\Exception;

use InvalidArgumentException;

/**
 * A date range that cannot describe a period.
 *
 * Raised rather than silently swapped, because a reversed range usually means the
 * caller's own dates are the wrong way round, and quietly fixing it would hide
 * the bug while returning data for a period nobody asked for.
 */
final class InvalidDateRange extends InvalidArgumentException
{
    public static function endBeforeStart(): self
    {
        return new self('The end of a date range cannot fall before its start.');
    }

    public static function tooLong(int $days, int $maximum): self
    {
        return new self(sprintf(
            'A range of %d days is longer than the %d-day maximum for this query.',
            $days,
            $maximum,
        ));
    }
}
