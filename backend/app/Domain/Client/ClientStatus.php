<?php

declare(strict_types=1);

namespace App\Domain\Client;

enum ClientStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    /** Archived is terminal: reactivating is a deliberate restore, not a transition. */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Prospect => in_array($target, [self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Paused, self::Archived], true),
            self::Paused => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
