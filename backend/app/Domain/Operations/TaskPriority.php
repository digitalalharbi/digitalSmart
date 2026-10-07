<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * How much a task matters relative to the others on the board.
 *
 * An **ordered** scale, which is why it carries a rank and why every chart of it uses the ordinal colour
 * ramp rather than categorical hues: swapping two of these changes the meaning.
 *
 * Priority is not a deadline, and this product refuses to treat it as one. An urgent task with no due
 * date is reported as urgent and undated — not quietly given today's date, which is the shortcut that
 * turns a board into a wall of red nobody reads. See `DueHorizon`.
 */
enum TaskPriority: string
{
    case Low = 'low';

    case Normal = 'normal';

    case High = 'high';

    /**
     * Someone is waiting now.
     *
     * Deliberately the top of a four-step scale rather than one of five: a scale with a step above
     * "urgent" invites everything to be urgent, and then nothing is.
     */
    case Urgent = 'urgent';

    /** Low is 0. Used for ordering and for the step on an ordinal colour ramp. */
    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /** Whether this priority is worth drawing attention to on a card. */
    public function isElevated(): bool
    {
        return $this === self::High || $this === self::Urgent;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The scale in order, lowest first.
     *
     * @return list<self>
     */
    public static function ascending(): array
    {
        $cases = self::cases();

        usort($cases, static fn (self $a, self $b): int => $a->rank() <=> $b->rank());

        return $cases;
    }
}
