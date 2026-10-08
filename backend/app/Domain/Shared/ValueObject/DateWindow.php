<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

use DateTimeImmutable;

/**
 * A requested date range, cut into consecutive windows no provider will refuse.
 *
 * Every provider this product reads publishes a longest range it will answer in one request — 92 days for Salla,
 * Zid and the GA4 Data API, and its own figure for each ad platform. A sync that asks for a year in one call
 * fails at the provider, and the failure arrives as "partial" on a client's store page with no explanation
 * anybody can act on.
 *
 * **One implementation, because three would disagree.** The advertising sync had this logic privately; the
 * commerce and measurement syncs did not have it at all, and both held an adapter that published the limit they
 * were ignoring. Date arithmetic copied into three files is date arithmetic that is off by one in one of them,
 * and the symptom is a single missing day in a client's report.
 *
 * **Inclusive at both ends**, because that is how every adapter filters a date: a window of seven days is seven
 * dates, and the next window starts on the eighth.
 *
 * **Not `DateRange`**, deliberately. That one is a validated business range and refuses to end before it starts,
 * which is right for a report somebody asked for. This is a transport concern: a degenerate range still has to
 * produce one request, because a sync that returned no windows would leave a journal with no record of having
 * been asked — and afterwards that reads as a sync that never ran rather than one that had nothing to do.
 */
final readonly class DateWindow
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {}

    /**
     * The range as windows of at most `$maxDays` days each.
     *
     * A range shorter than one window is one window. A range that ends before it starts is **still one window**
     * rather than none: the caller asked for a sync, and returning nothing would leave a journal with no record
     * of having been asked — which reads afterwards as a sync that never ran.
     *
     * @return list<self>
     */
    public static function split(DateTimeImmutable $from, DateTimeImmutable $until, int $maxDays): array
    {
        $span = max(1, $maxDays);
        $windows = [];
        $cursor = $from;

        while ($cursor <= $until) {
            $edge = $cursor->modify('+'.($span - 1).' days');
            $windows[] = new self($cursor, $edge > $until ? $until : $edge);
            $cursor = $edge->modify('+1 day');
        }

        return $windows === [] ? [new self($from, $until)] : $windows;
    }
}
