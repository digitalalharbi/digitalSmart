<?php

declare(strict_types=1);

namespace App\Domain\Project;

enum ProjectStatus: string
{
    /** Stored as `planning`; presented as "Draft". Renaming a stored value costs a migration for a word. */
    case Planning = 'planning';
    /** Accounts, tracking and team are being set up. Syncing, so the first figures exist at readiness. */
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Paused = 'paused';
    /** Winding down. Still read: the final report is built from what lands in these last weeks. */
    case Closing = 'closing';
    case Completed = 'completed';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Planning => in_array($target, [self::Onboarding, self::Active, self::Archived], true),
            self::Onboarding => in_array($target, [self::Planning, self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Paused, self::Closing, self::Completed, self::Archived], true),
            self::Paused => in_array($target, [self::Active, self::Closing, self::Completed, self::Archived], true),
            self::Closing => in_array($target, [self::Active, self::Completed, self::Archived], true),
            // A completed project may be archived, or reopened if work resumes.
            self::Completed => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }

    /** Whether campaigns under this project may still spend. */
    public function allowsSpend(): bool
    {
        return $this === self::Active || $this === self::Closing;
    }

    /** Whether its accounts are still worth reading on a schedule. Paused is: it resumes, and its figures matter. */
    public function isLive(): bool
    {
        return in_array($this, [self::Planning, self::Onboarding, self::Active, self::Paused, self::Closing], true);
    }
}
