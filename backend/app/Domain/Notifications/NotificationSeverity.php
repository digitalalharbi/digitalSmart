<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * How much a notification should stand out — decided by a rule, never by a hand.
 *
 * Four words, and the rule that assigns them is the one place colour comes from. An alert coloured red because
 * somebody felt it was serious teaches people that red means "somebody felt"; an alert coloured red because the
 * cost per result rose by half teaches them what red means. `forAdverseChange` is that rule for a figure that
 * moved; the thresholds are the same ones the campaign health policy already reads a cost rise by.
 */
enum NotificationSeverity: string
{
    case Info = 'info';
    case Success = 'success';
    case Attention = 'attention';
    case Critical = 'critical';

    /** Half worse is critical, a fifth worse is attention; the campaign health policy draws the same two lines. */
    public const float CRITICAL_RATIO = 0.5;

    public const float ATTENTION_RATIO = 0.2;

    /**
     * The severity of a figure that moved against the reader.
     *
     * `$adverse` is the size of the move in the bad direction as a ratio of the earlier figure: +0.3 means thirty
     * percent worse, −0.3 thirty percent better. A move in the good direction is `success` only past the
     * attention line, because a two percent improvement is not news.
     */
    public static function forAdverseChange(float $adverse): self
    {
        if ($adverse >= self::CRITICAL_RATIO) {
            return self::Critical;
        }

        if ($adverse >= self::ATTENTION_RATIO) {
            return self::Attention;
        }

        if ($adverse <= -self::ATTENTION_RATIO) {
            return self::Success;
        }

        return self::Info;
    }

    /** Higher is worse. Used to decide whether a repeat of an alert says something new. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Success => 1,
            self::Attention => 2,
            self::Critical => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Success => 'Good news',
            self::Attention => 'Needs attention',
            self::Critical => 'Critical',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
