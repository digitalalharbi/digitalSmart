<?php

declare(strict_types=1);

namespace App\Domain\Client;

enum ClientStatus: string
{
    case Prospect = 'prospect';
    /** Signed, and being set up: accounts, tracking and team are not finished. */
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Paused = 'paused';
    /**
     * Left the agency. Nothing recurring is spent on them and nothing they produced is deleted.
     *
     * Not archived: an offboarded client may come back, and archiving is the quieter, permanent state.
     */
    case Offboarded = 'offboarded';
    case Archived = 'archived';

    /** Archived is terminal: reactivating is a deliberate restore, not a transition. */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Prospect => in_array($target, [self::Onboarding, self::Active, self::Archived], true),
            self::Onboarding => in_array($target, [self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Paused, self::Offboarded, self::Archived], true),
            self::Paused => in_array($target, [self::Active, self::Offboarded, self::Archived], true),
            self::Offboarded => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }

    /** Whether recurring work — syncs, digests, portal access — is still being spent on this client. */
    public function isLeaving(): bool
    {
        return $this === self::Offboarded || $this === self::Archived;
    }
}
