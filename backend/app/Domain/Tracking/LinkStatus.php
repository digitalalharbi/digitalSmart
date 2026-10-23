<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use DateTimeImmutable;

enum LinkStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    /** Expiry is derived, never stored: a stored flag needs something to write it, and that thing is what would fail. */
    public function redirects(?DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        return $this === self::Active && ($expiresAt === null || $expiresAt > $now);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
