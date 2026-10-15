<?php

declare(strict_types=1);

namespace App\Domain\Project;

/**
 * One readiness dimension's answer.
 *
 * `Unknown` is a real state and is not worth less than the others: the product does not model landing pages,
 * and saying "ready" about something it cannot see would be the one lie a readiness check must not tell.
 * Unknown dimensions are shown, and never count toward the overall answer in either direction.
 */
enum ReadinessState: string
{
    case Ready = 'ready';
    case NeedsAttention = 'needs_attention';
    case Blocked = 'blocked';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::NeedsAttention => 'Needs attention',
            self::Blocked => 'Blocked',
            self::Unknown => 'Not tracked',
        };
    }
}
