<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Who can see a saved view or a dashboard.
 *
 * Deliberately three states rather than a boolean. "Mine" and "the workspace's" are
 * the common cases, but an agency's real need is the third: a dashboard built for
 * one client's project, which the people on that project see and nobody else does —
 * including other members of the same workspace working for a different client.
 */
enum Visibility: string
{
    /** Only its owner. */
    case Private = 'private';
    /** Everyone in the workspace who can view analytics. */
    case Workspace = 'workspace';
    /**
     * Everyone assigned to the project it is scoped to.
     *
     * Requires a project; without one the scope would be undefined, which is why
     * `requiresProject()` exists and the database enforces it.
     */
    case Project = 'project';

    public function requiresProject(): bool
    {
        return $this === self::Project;
    }

    /** Whether anyone other than the owner can see it. */
    public function isShared(): bool
    {
        return $this !== self::Private;
    }

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Only me',
            self::Workspace => 'Everyone in the workspace',
            self::Project => 'The project team',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
