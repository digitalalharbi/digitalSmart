<?php

declare(strict_types=1);

namespace App\Application\Workspace\DTO;

/**
 * A colleague who has just been added, and the one-time password to read out to them.
 *
 * **The password is in this object and nowhere else.** It is returned once, in the response to the request that
 * created the account, and never stored in plaintext, never logged, never audited and never readable again. If
 * the administrator loses it, the honest remedy is to add nothing and set a new one — not to look the old one up,
 * because there is nowhere to look.
 */
final readonly class InvitedMember
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $roleSlug,
        /** Shown to the administrator once. The member must replace it before they can do anything else. */
        public string $oneTimePassword,
    ) {}
}
