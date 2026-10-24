<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';

    /** Ended. The row is kept as the record of who was here; inviting the person again reactivates it. */
    case Revoked = 'revoked';

    /** Only an active member may act in the workspace. */
    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
