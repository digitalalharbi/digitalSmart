<?php

declare(strict_types=1);

namespace App\Application\Digest\DTO;

use App\Domain\Attention\DigestKind;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The period a performance digest reads, and the one it compares against.
 *
 * Decided in the workspace's own time zone, because "yesterday" at nine in Riyadh is not "yesterday" in UTC — and
 * every range ends before today, since a day still running reads as a drop.
 */
final readonly class PerformanceDigestPeriod
{
    public function __construct(
        public DigestKind $kind,
        public DateRange $current,
        public DateRange $previous,
        /** The day the digest is filed under: the last day of its period. */
        public DateTimeImmutable $on,
    ) {}

    public static function for(DigestKind $kind, DateTimeImmutable $now, DateTimeZone $zone): self
    {
        $today = new DateTimeImmutable($now->setTimezone($zone)->format('Y-m-d'), $zone);

        if ($kind === DigestKind::MonthlyPerformance) {
            $firstOfThisMonth = $today->modify('first day of this month');
            $current = new DateRange($firstOfThisMonth->modify('-1 month'), $firstOfThisMonth->modify('-1 day'));
        } else {
            $current = DateRange::lastDays($kind === DigestKind::WeeklyPerformance ? 7 : 1, $today);
        }

        return new self($kind, $current, $current->previousPeriod(), $current->end);
    }
}
