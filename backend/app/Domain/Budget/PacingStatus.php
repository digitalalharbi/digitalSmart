<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * What a budget's spend to date says about how it is running.
 *
 * Seven states, because "over budget" and "spending too fast" are different problems
 * with different answers, and so are "has not started" and "spent nothing". A single
 * percentage cannot carry that, and a reader deciding whether to intervene today needs
 * the distinction more than the number.
 */
enum PacingStatus: string
{
    /** The period has not begun. Spend of zero is correct, not a warning. */
    case NotStarted = 'not_started';

    /** Spending within tolerance of an even pace. */
    case OnTrack = 'on_track';

    /** Behind pace: the budget will not be spent at this rate. */
    case Underspending = 'underspending';

    /** Ahead of pace: the budget will run out before the period ends. */
    case Overspending = 'overspending';

    /** The budget is spent. Nothing more will deliver, whatever the dates say. */
    case Exhausted = 'exhausted';

    /** The period is over. What was spent is what was spent. */
    case Ended = 'ended';

    /**
     * Pacing cannot be judged.
     *
     * A budget with no approved amount, or a period that makes no sense. Reported
     * rather than defaulted to "on track", which would be a reassurance nobody checked.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::OnTrack => 'On track',
            self::Underspending => 'Underspending',
            self::Overspending => 'Overspending',
            self::Exhausted => 'Budget spent',
            self::Ended => 'Ended',
            self::Unknown => 'Unknown',
        };
    }

    /** Whether somebody should look at this today. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Overspending, self::Underspending, self::Exhausted], true);
    }

    /**
     * Whether this status is about money being spent too fast.
     *
     * Separate from `needsAttention()` because the two call for opposite actions:
     * overspending is throttled, underspending is pushed.
     */
    public function isOverspend(): bool
    {
        return in_array($this, [self::Overspending, self::Exhausted], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
