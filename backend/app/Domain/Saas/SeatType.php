<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Access\SystemRole;

/**
 * The commercial kind of a membership. It is not a role: a seat says what the workspace pays for, a role says what the person
 * may do, and changing one never changes the other.
 */
enum SeatType: string
{
    case Full = 'full';
    case Limited = 'limited';
    case Client = 'client';
    case Guest = 'guest';

    /** Which limit a seat of this kind uses. */
    public function countsAgainst(): Limit
    {
        return match ($this) {
            self::Full, self::Limited => Limit::InternalUsers,
            self::Client, self::Guest => Limit::ClientUsers,
        };
    }

    /** The seat somebody takes when nobody says otherwise: a client's people take a client seat, the agency's a full one. */
    public static function defaultFor(?string $roleSlug): self
    {
        return $roleSlug === SystemRole::ClientViewer->value ? self::Client : self::Full;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
