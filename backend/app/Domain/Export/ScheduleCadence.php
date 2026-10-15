<?php

declare(strict_types=1);

namespace App\Domain\Export;

use DateTimeImmutable;

/**
 * How often a report is made again.
 *
 * Times are **UTC**, said on the surface: an agency spans offices, and a schedule that silently meant "the
 * server's morning" would deliver the Monday report on Sunday for somebody.
 */
enum ScheduleCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    /**
     * The first run strictly after `$from`.
     *
     * `$weekday` is 0 (Sunday) – 6; `$dayOfMonth` is 1–28, capped there so "the 31st" never means a month
     * that has none and the report is never silently skipped in February.
     */
    public function next(DateTimeImmutable $from, int $hour, ?int $weekday, ?int $dayOfMonth): DateTimeImmutable
    {
        $at = $from->setTime($hour, 0, 0);

        return match ($this) {
            self::Daily => $at > $from ? $at : $at->modify('+1 day'),
            self::Weekly => $this->nextWeekly($from, $at, $weekday ?? 1),
            self::Monthly => $this->nextMonthly($from, $at, min(max($dayOfMonth ?? 1, 1), 28)),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    private function nextWeekly(DateTimeImmutable $from, DateTimeImmutable $at, int $weekday): DateTimeImmutable
    {
        $delta = ($weekday - (int) $at->format('w') + 7) % 7;
        $candidate = $at->modify(sprintf('+%d days', $delta));

        return $candidate > $from ? $candidate : $candidate->modify('+7 days');
    }

    private function nextMonthly(DateTimeImmutable $from, DateTimeImmutable $at, int $day): DateTimeImmutable
    {
        $candidate = $at->setDate((int) $at->format('Y'), (int) $at->format('n'), $day);

        return $candidate > $from ? $candidate : $candidate->modify('first day of next month')->setDate(
            (int) $candidate->modify('first day of next month')->format('Y'),
            (int) $candidate->modify('first day of next month')->format('n'),
            $day,
        );
    }
}
