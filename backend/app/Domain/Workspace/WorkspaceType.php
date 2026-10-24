<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * What kind of organisation a workspace is.
 *
 * It may change defaults, navigation emphasis and wording. It must never change authorization or isolation: an agency, a
 * brand, a freelancer and an internal team are held to exactly the same rules.
 */
enum WorkspaceType: string
{
    case Agency = 'agency';
    case Brand = 'brand';
    case Freelancer = 'freelancer';
    case InternalTeam = 'internal_team';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
