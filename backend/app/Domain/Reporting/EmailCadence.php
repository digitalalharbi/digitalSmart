<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use DateTimeImmutable;

/**
 * How often a client is emailed a report's link.
 *
 * Weekly and monthly only. A report's own windows end yesterday and a client reads a period, not a ticker; a daily
 * email of a link is the one a client mutes, after which the weekly one is not read either.
 */
enum EmailCadence: string
{
    case Weekly = 'weekly';

    case Monthly = 'monthly';

    /**
     * The period a moment falls in, as the key one email is sent for.
     *
     * One email per subscription per key is the whole of the duplicate rule: a run that happens twice in a week finds
     * the key already claimed. ISO weeks, so a week that straddles a new year has one key rather than two.
     */
    public function periodKey(DateTimeImmutable $at): string
    {
        return match ($this) {
            self::Weekly => $at->format('o-\WW'),
            self::Monthly => $at->format('Y-m'),
        };
    }

    /** How long the emailed link stays open: the period, and a margin for somebody who reads it late. */
    public function linkDays(): int
    {
        return match ($this) {
            self::Weekly => 14,
            self::Monthly => 45,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
