<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\ValueObject\DateRange;

/**
 * Whether two periods may be compared, and what to say when they may not.
 *
 * The honesty centrepiece of this module. "Spend is up 40%" is the single most quoted sentence an agency
 * produces, and it is worthless — sometimes actively misleading — when the two periods are not the same length or
 * when one of them is still running. Both of those are **computable**, so this product computes them rather than
 * leaving a reader to notice.
 *
 * What it deliberately does **not** claim is seasonality. Comparing Ramadan to the month after it is a bad
 * comparison and no code here can know that, so the object says nothing about it: an honest `Comparable` means
 * "the arithmetic is sound", not "this comparison is meaningful". Pretending otherwise would be the more
 * dangerous error, because a reader who has been told the comparison is valid stops thinking about it.
 */
final readonly class Comparability
{
    private function __construct(
        public bool $isComparable,
        /** Null when comparable. Otherwise the sentence a reader needs, in place of the percentage. */
        public ?string $reason,
        public ?string $code,
    ) {}

    public static function of(DateRange $period, DateRange $against, bool $periodIsIncomplete): self
    {
        $length = $period->lengthInDays();
        $againstLength = $against->lengthInDays();

        if ($length !== $againstLength) {
            /*
             * The common and quiet failure: 30 days against a calendar month, or this month-to-date against all of
             * last month. A percentage between them measures the difference in length as much as the difference in
             * performance, and nothing on the page would say so.
             */
            return new self(
                false,
                sprintf(
                    'These periods are different lengths — %d days against %d — so a change between them '
                    .'measures the calendar as much as the campaigns.',
                    $length,
                    $againstLength,
                ),
                'unequal_length',
            );
        }

        if ($periodIsIncomplete) {
            /*
             * Equal in length and still wrong: a month-to-date period has the same number of days as the window
             * before it only because the window before it was cut to match, and the current one is still filling.
             */
            return new self(
                false,
                'This period is still running, so it is being compared with a finished one. The change will '
                .'move for the rest of the period.',
                'period_incomplete',
            );
        }

        if ($period->start <= $against->end) {
            // Overlapping periods share days, so part of any change is the same data counted twice.
            return new self(
                false,
                'These periods overlap, so some of the change is the same days counted on both sides.',
                'overlapping',
            );
        }

        return new self(true, null, null);
    }

    /** A comparison nobody asked for. Not a failure — just nothing to say. */
    public static function none(): self
    {
        return new self(false, null, 'not_requested');
    }

    public function wasRequested(): bool
    {
        return $this->code !== 'not_requested';
    }
}
