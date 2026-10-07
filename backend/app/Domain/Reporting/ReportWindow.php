<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;

/**
 * The period a saved report covers, stored as a **rule** rather than as two dates.
 *
 * This is the difference between a report worth saving and a report worth running once. "Last 30 days" stays
 * useful next month; `2026-09-01 to 2026-09-30` becomes a report somebody has to remember to edit, and the one
 * they forget is the one that gets sent.
 *
 * `Custom` exists because a specific month genuinely is what somebody wants sometimes — a closing report for
 * September is about September forever — and it carries its dates on the report row.
 *
 * Every relative window **ends yesterday**, which `DateRange::lastDays()` already enforces: today is still
 * accumulating, so including it makes every metric look worse than it is and makes a period comparison compare a
 * partial day with a whole one.
 */
enum ReportWindow: string
{
    case Last7Days = 'last_7_days';

    case Last14Days = 'last_14_days';

    case Last28Days = 'last_28_days';

    case Last30Days = 'last_30_days';

    case Last90Days = 'last_90_days';

    /** The calendar month before this one, whole. What a monthly report is actually about. */
    case LastMonth = 'last_month';

    /** This month so far. Deliberately labelled as incomplete wherever it is shown. */
    case MonthToDate = 'month_to_date';

    case Custom = 'custom';

    /**
     * Resolve to real dates.
     *
     * `$from` and `$until` are only read for `Custom`, and are required there — a custom window with no dates is
     * refused by the schema rather than silently resolved to something plausible.
     */
    public function resolve(
        DateTimeImmutable $today,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $until = null,
    ): DateRange {
        return match ($this) {
            self::Last7Days => DateRange::lastDays(7, $today),
            self::Last14Days => DateRange::lastDays(14, $today),
            self::Last28Days => DateRange::lastDays(28, $today),
            self::Last30Days => DateRange::lastDays(30, $today),
            self::Last90Days => DateRange::lastDays(90, $today),
            self::LastMonth => new DateRange(
                $today->modify('first day of last month')->setTime(0, 0),
                $today->modify('last day of last month')->setTime(0, 0),
            ),
            /*
             * Ends yesterday, like every relative window. A month-to-date report that included today would
             * disagree with itself depending on the hour it was opened.
             */
            self::MonthToDate => new DateRange(
                $today->modify('first day of this month')->setTime(0, 0),
                $today->modify('-1 day')->setTime(0, 0),
            ),
            self::Custom => new DateRange(
                ($from ?? $today->modify('-30 days'))->setTime(0, 0),
                ($until ?? $today->modify('-1 day'))->setTime(0, 0),
            ),
        };
    }

    /**
     * Whether this window covers a period that is still running.
     *
     * True only for month-to-date. A report over an incomplete period is perfectly legitimate and is also the one
     * most likely to be compared against a whole month, so every surface that shows it says so.
     */
    public function isIncomplete(): bool
    {
        return $this === self::MonthToDate;
    }

    public function needsDates(): bool
    {
        return $this === self::Custom;
    }

    public function label(): string
    {
        return match ($this) {
            self::Last7Days => 'The last 7 days',
            self::Last14Days => 'The last 14 days',
            self::Last28Days => 'The last 28 days',
            self::Last30Days => 'The last 30 days',
            self::Last90Days => 'The last 90 days',
            self::LastMonth => 'Last month',
            self::MonthToDate => 'This month so far',
            self::Custom => 'A specific period',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
