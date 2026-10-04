<?php

declare(strict_types=1);

namespace App\Domain\Project;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Planning => in_array($target, [self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Paused, self::Completed, self::Archived], true),
            self::Paused => in_array($target, [self::Active, self::Completed, self::Archived], true),
            // A completed project may be archived, or reopened if work resumes.
            self::Completed => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }

    /** Whether campaigns under this project may still spend. */
    public function allowsSpend(): bool
    {
        return $this === self::Active;
    }
}
