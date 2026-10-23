<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Where a request for an account stands.
 *
 * Approving one **creates nothing**: it records that somebody decided to say yes, and the person who decided then adds
 * the member through the ordinary flow. There is no sender identity to deliver an invitation, so an approval that
 * quietly made an account would be an account whose owner nobody had told.
 */
enum AccessRequestStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Approved = 'approved';
    case Declined = 'declined';

    /** Approved and declined are decisions; moving off one would make the record say two things in turn. */
    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next !== self::Pending,
            self::Contacted => $next === self::Approved || $next === self::Declined,
            self::Approved, self::Declined => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
