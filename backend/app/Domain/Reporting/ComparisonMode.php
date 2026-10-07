<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\ValueObject\DateRange;

/** What a report compares its period against, if anything. */
enum ComparisonMode: string
{
    case None = 'none';

    /** The equally long period immediately before. The only comparison this product computes without caveat. */
    case PreviousPeriod = 'previous_period';

    /**
     * The same dates a year earlier.
     *
     * Offered because an agency's clients ask for it, and it is the comparison most likely to be the same length
     * and least likely to be meaningful: a year is long enough for the account, the creative, the audience and the
     * product to have changed. The arithmetic is sound and the surface says what it cannot account for.
     */
    case SamePeriodLastYear = 'same_period_last_year';

    public function resolve(DateRange $period): ?DateRange
    {
        return match ($this) {
            self::None => null,
            self::PreviousPeriod => $period->previousPeriod(),
            self::SamePeriodLastYear => new DateRange(
                $period->start->modify('-1 year'),
                $period->end->modify('-1 year'),
            ),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No comparison',
            self::PreviousPeriod => 'The period before',
            self::SamePeriodLastYear => 'The same period last year',
        };
    }

    /**
     * What this comparison cannot account for, or null where there is nothing to add.
     *
     * Stated per mode rather than once, because the two modes fail differently and a single generic warning is one
     * nobody reads.
     */
    public function caveat(): ?string
    {
        return match ($this) {
            self::None => null,
            self::PreviousPeriod => 'A change against the period before cannot tell seasonality from performance. '
                .'Thirty days containing a holiday are not thirty ordinary days.',
            self::SamePeriodLastYear => 'A year is long enough for the account, the creative, the audience and the '
                .'product to have changed. The arithmetic is sound; what it means is a judgement.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
