<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use DateTimeImmutable;

/**
 * How a budget is running, worked out from four facts and nothing else.
 *
 * The budget, the period, what has actually been spent, and today. Everything here is
 * derived from those, so a figure on a pacing screen can always be traced back to a
 * number a provider reported and a number a client approved.
 *
 * Two deliberate refusals:
 *
 *   - **A projection needs elapsed days.** Before the first day of a period there is
 *     nothing to extrapolate from, so `projectedMinor` is null rather than zero. Zero
 *     would read as "forecast: nothing will be spent", which is a claim.
 *   - **Pacing is even by default.** No weighting by weekday, platform or past
 *     delivery, because a weighted pace is a forecast wearing a plan's clothes: a
 *     reader comparing today's spend against "expected" has to know exactly what
 *     expected means, and "the budget divided by the days" is the only definition that
 *     needs no footnote.
 */
final readonly class Pacing
{
    /**
     * How far from an even pace still counts as on track.
     *
     * Ten per cent. Tighter and every campaign is permanently amber on ordinary daily
     * variation; looser and a budget can be a third overspent before anyone is told.
     */
    public const float TOLERANCE = 0.10;

    /**
     * Below this share of the budget, a projection is an under-delivery risk.
     *
     * Ninety per cent: a plan that lands more than a tenth short has bought
     * materially less than the client paid for, which is a conversation to have before
     * the period ends rather than after.
     */
    public const float UNDER_DELIVERY_THRESHOLD = 0.90;

    private function __construct(
        public PacingStatus $status,
        public int $budgetMinor,
        public int $spentMinor,
        /** What an even pace would have spent by today. */
        public int $expectedMinor,
        /** Spent minus expected. Positive is ahead of pace. */
        public int $varianceMinor,
        /** Variance as a share of expected, or null when nothing was expected yet. */
        public ?float $variancePercent,
        /** Budget minus spent. Negative when the budget is overspent. */
        public int $remainingMinor,
        /** Where this rate of spend lands by the end, or null with nothing to project from. */
        public ?int $projectedMinor,
        /** What may be spent per remaining day to land exactly on budget, if any remain. */
        public ?int $dailyAllowanceMinor,
        public int $totalDays,
        public int $elapsedDays,
        public int $remainingDays,
        public string $currency,
    ) {}

    /**
     * @param  int  $budgetMinor  the approved amount, in minor units
     * @param  int  $spentMinor  actual spend to date, in the same currency
     */
    public static function compute(
        int $budgetMinor,
        int $spentMinor,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        DateTimeImmutable $today,
        string $currency,
    ): self {
        $start = $periodStart->setTime(0, 0);
        $end = $periodEnd->setTime(0, 0);
        $now = $today->setTime(0, 0);

        // Inclusive of both ends: a one-day budget covers one day, not zero.
        $totalDays = $end < $start ? 0 : (int) $start->diff($end)->days + 1;

        if ($totalDays === 0 || $budgetMinor <= 0) {
            /*
             * Nothing to pace against. Reported as unknown rather than treated as a
             * budget of zero that is permanently exhausted, which would raise an alert
             * about a budget nobody has set yet.
             */
            return new self(
                status: PacingStatus::Unknown,
                budgetMinor: $budgetMinor,
                spentMinor: $spentMinor,
                expectedMinor: 0,
                varianceMinor: $spentMinor,
                variancePercent: null,
                remainingMinor: $budgetMinor - $spentMinor,
                projectedMinor: null,
                dailyAllowanceMinor: null,
                totalDays: $totalDays,
                elapsedDays: 0,
                remainingDays: 0,
                currency: $currency,
            );
        }

        $elapsedDays = match (true) {
            $now < $start => 0,
            $now >= $end => $totalDays,
            default => (int) $start->diff($now)->days + 1,
        };

        $remainingDays = max(0, $totalDays - $elapsedDays);
        $expectedMinor = (int) round($budgetMinor * $elapsedDays / $totalDays);
        $varianceMinor = $spentMinor - $expectedMinor;
        $remainingMinor = $budgetMinor - $spentMinor;

        return new self(
            status: self::statusFor($budgetMinor, $spentMinor, $expectedMinor, $elapsedDays, $now, $start, $end),
            budgetMinor: $budgetMinor,
            spentMinor: $spentMinor,
            expectedMinor: $expectedMinor,
            varianceMinor: $varianceMinor,
            variancePercent: $expectedMinor > 0 ? $varianceMinor / $expectedMinor : null,
            remainingMinor: $remainingMinor,
            // Today's rate carried to the end of the period.
            projectedMinor: $elapsedDays > 0 ? (int) round($spentMinor / $elapsedDays * $totalDays) : null,
            /*
             * What is left, spread over the days that are left. Null once the period is
             * over: there is no day left to spend it on, and a figure there would invite
             * someone to try.
             */
            dailyAllowanceMinor: $remainingDays > 0 ? (int) round(max(0, $remainingMinor) / $remainingDays) : null,
            totalDays: $totalDays,
            elapsedDays: $elapsedDays,
            remainingDays: $remainingDays,
            currency: $currency,
        );
    }

    /** Whether this rate of spend lands over the approved budget. */
    public function risksOverspend(): bool
    {
        return $this->projectedMinor !== null
            && $this->budgetMinor > 0
            && $this->projectedMinor > $this->budgetMinor;
    }

    /**
     * Whether this rate of spend lands materially short.
     *
     * Only while the period is still running: once it has ended, a short landing is
     * not a risk any more, it is the result.
     */
    public function risksUnderDelivery(): bool
    {
        return $this->projectedMinor !== null
            && $this->remainingDays > 0
            && $this->budgetMinor > 0
            && $this->projectedMinor < (int) round($this->budgetMinor * self::UNDER_DELIVERY_THRESHOLD);
    }

    /** Spend as a share of the approved budget, or null when there is no budget. */
    public function utilisation(): ?float
    {
        return $this->budgetMinor > 0 ? $this->spentMinor / $this->budgetMinor : null;
    }

    /** Elapsed time as a share of the period. */
    public function periodElapsed(): ?float
    {
        return $this->totalDays > 0 ? $this->elapsedDays / $this->totalDays : null;
    }

    private static function statusFor(
        int $budgetMinor,
        int $spentMinor,
        int $expectedMinor,
        int $elapsedDays,
        DateTimeImmutable $now,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): PacingStatus {
        if ($now < $start) {
            return PacingStatus::NotStarted;
        }

        /*
         * Checked before the period end, because a budget that ran out on day three
         * has a problem worth naming for the rest of the month — "ended" would bury it.
         */
        if ($spentMinor >= $budgetMinor) {
            return PacingStatus::Exhausted;
        }

        if ($now > $end) {
            return PacingStatus::Ended;
        }

        if ($expectedMinor <= 0 || $elapsedDays === 0) {
            return PacingStatus::OnTrack;
        }

        $variance = ($spentMinor - $expectedMinor) / $expectedMinor;

        return match (true) {
            abs($variance) <= self::TOLERANCE => PacingStatus::OnTrack,
            $variance > 0 => PacingStatus::Overspending,
            default => PacingStatus::Underspending,
        };
    }
}
