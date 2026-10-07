<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use DateTimeImmutable;

/**
 * How long something has been waiting, as the four answers an agency acts on.
 *
 * Used for two different queues, which is the reason it is its own enum rather than two sets of literals:
 * requests waiting for a decision, and **approved changes waiting for somebody to carry them out**. The
 * second is the queue nobody has, and it is the one where an old entry is a client being told something
 * untrue.
 *
 * Measured in whole days from a moment, against a moment passed in, so every row on one screen is aged
 * against the same instant.
 */
enum ApprovalAge: string
{
    case Today = 'today';

    case Days = 'days';

    case Week = 'week';

    /** More than a week. On the enactment queue, this is the bucket that should be empty. */
    case Stale = 'stale';

    public static function of(DateTimeImmutable $since, DateTimeImmutable $now): self
    {
        $days = (int) $since->setTime(0, 0)->diff($now->setTime(0, 0))->days;

        return match (true) {
            $days <= 0 => self::Today,
            $days <= 3 => self::Days,
            $days <= 7 => self::Week,
            default => self::Stale,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Today => 0,
            self::Days => 1,
            self::Week => 2,
            self::Stale => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Days => 'One to three days',
            self::Week => 'Four to seven days',
            self::Stale => 'Over a week',
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::Week || $this === self::Stale;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
