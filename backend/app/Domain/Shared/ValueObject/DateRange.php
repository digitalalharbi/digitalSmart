<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use App\Domain\Shared\Exception\InvalidDateRange;
use DateTimeImmutable;

/**
 * An inclusive range of account-local dates.
 *
 * Dates, not timestamps. A platform's "day" is defined in its account's timezone,
 * and converting to UTC would shift spend between days — which is how a Monday
 * report stops matching the platform's own.
 */
/*
 * Moved out of the application layer, where it started.
 *
 * A date range that knows its own length, the equally long period before it and the same dates a year earlier is
 * arithmetic about a business concept, not a transport shape — and the reporting enums that resolve a saved window
 * into one are domain rules. Leaving it in `Application\Analytics\DTO` meant the domain depending on the
 * application layer, which the architecture test caught and was right to.
 */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($start > $end) {
            throw InvalidDateRange::endsBeforeStart();
        }
    }

    public static function lastDays(int $days, DateTimeImmutable $today): self
    {
        /*
         * Ends yesterday, not today. Today is still accumulating, so including it
         * makes every metric look worse than it is and a day-over-day comparison
         * compare a partial day with a whole one.
         */
        $end = $today->modify('-1 day');

        return new self($end->modify('-'.max(0, $days - 1).' days'), $end);
    }

    /** The equally long range immediately before this one. */
    public function previousPeriod(): self
    {
        $length = $this->lengthInDays();
        $end = $this->start->modify('-1 day');

        return new self($end->modify('-'.($length - 1).' days'), $end);
    }

    /** The same range one year earlier, for seasonal comparison. */
    public function previousYear(): self
    {
        return new self($this->start->modify('-1 year'), $this->end->modify('-1 year'));
    }

    public function lengthInDays(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function contains(DateTimeImmutable $date): bool
    {
        return $date->format('Y-m-d') >= $this->startDate()
            && $date->format('Y-m-d') <= $this->endDate();
    }

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end->format('Y-m-d');
    }
}
