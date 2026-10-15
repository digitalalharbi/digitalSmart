<?php

declare(strict_types=1);

namespace App\Domain\Attention;

/**
 * Whose attention items to show — a narrowing over what the reader may already see, never a widening.
 *
 * An administrator sees the whole agency's queue; "my projects" is how they ask for the part that is theirs
 * to act on. Items that name no project or client the person belongs to drop out of a narrowed view, and the
 * view says it is narrowed.
 */
enum AttentionAudience: string
{
    case Everything = 'all';
    case MyClients = 'my_clients';
    case MyProjects = 'my_projects';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
