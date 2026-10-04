<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    /** Only an active member may act in the workspace. */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
