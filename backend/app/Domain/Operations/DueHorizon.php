<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use DateTimeImmutable;

/**
 * How soon a task is due, as the five answers a board can honestly give.
 *
 * The whole point of this enum is the last case. **A task with no due date is undated, not "later".**
 * Folding the two together is the single most common way a work board lies: it reports a tidy pipeline
 * while a third of the work has no date on it at all, and nobody notices until something is late. Undated
 * is therefore a bucket of its own, drawn as its own bar, and counted in its own figure.
 *
 * Two further rules:
 *
 *  - **Only open work can be overdue.** A task finished a week after its due date was late; it is not
 *    *overdue*, because nothing is outstanding. Counting completed work as overdue produces a number that
 *    can never be cleared.
 *  - **The comparison is by date, not by instant.** A task due today is due today until the day ends, in
 *    the reader's own day. Comparing a date against a timestamp makes everything due today overdue by
 *    lunchtime.
 */
enum DueHorizon: string
{
    case Overdue = 'overdue';

    case Today = 'today';

    case ThisWeek = 'this_week';

    case Later = 'later';

    /** No due date was ever set. Its own bucket — see the note above. */
    case Undated = 'undated';

    /**
     * Which bucket a task falls into.
     *
     * `$dueOn` is a date; `$today` is the reader's today. Closed work is never overdue, so a closed task
     * is placed by its date alone and a closed task with no date is undated.
     */
    public static function of(?DateTimeImmutable $dueOn, DateTimeImmutable $today, bool $isOpen): self
    {
        if ($dueOn === null) {
            return self::Undated;
        }

        $due = (int) $dueOn->format('Ymd');
        $now = (int) $today->format('Ymd');

        if ($due < $now) {
            // Closed work that was late is still closed. Only outstanding work is overdue.
            return $isOpen ? self::Overdue : self::Later;
        }

        if ($due === $now) {
            return self::Today;
        }

        $days = (int) $today->setTime(0, 0)->diff($dueOn->setTime(0, 0))->days;

        return $days <= 7 ? self::ThisWeek : self::Later;
    }

    /** The order a reader scans, soonest first. Undated sits at the end because it has no position. */
    public function rank(): int
    {
        return match ($this) {
            self::Overdue => 0,
            self::Today => 1,
            self::ThisWeek => 2,
            self::Later => 3,
            self::Undated => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overdue => 'Overdue',
            self::Today => 'Due today',
            self::ThisWeek => 'Due within a week',
            self::Later => 'Later',
            self::Undated => 'No due date',
        };
    }

    /** Whether this bucket is something somebody should act on today. */
    public function needsAttention(): bool
    {
        return $this === self::Overdue || $this === self::Today;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The buckets in reading order.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
