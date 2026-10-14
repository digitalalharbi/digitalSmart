<?php

declare(strict_types=1);

namespace App\Domain\Attention;

/**
 * How loudly something is asking.
 *
 * Three levels, not six: this is a queue of work, and the three health states that are *not* work —
 * healthy, inactive, not-enough-data — have nothing to contribute to it. Keeping the same three words the
 * health vocabulary uses means a campaign that reads *Critical* on the control center reads *Critical*
 * here too.
 */
enum AttentionSeverity: string
{
    case Critical = 'critical';
    case NeedsAttention = 'needs_attention';
    case Watch = 'watch';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::NeedsAttention => 'Needs attention',
            self::Watch => 'Watch',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::NeedsAttention => 1,
            self::Watch => 2,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
