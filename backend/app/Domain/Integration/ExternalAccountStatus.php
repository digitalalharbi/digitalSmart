<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/** Mirrors what the provider reports about the account, not our opinion of it. */
enum ExternalAccountStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Closed = 'closed';
    case Unsettled = 'unsettled';
    case Unknown = 'unknown';

    /**
     * Whether it makes sense to bind this account to a project.
     *
     * An unsettled account can still be read, so it stays bindable — the reason
     * is surfaced in the interface rather than the account being hidden.
     */
    public function isUsable(): bool
    {
        return in_array($this, [self::Active, self::Unsettled, self::Unknown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Disabled => 'Disabled',
            self::Closed => 'Closed',
            self::Unsettled => 'Unsettled billing',
            self::Unknown => 'Unknown',
        };
    }
}
