<?php

declare(strict_types=1);

namespace App\Application\Identity\Contract;

use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;

/**
 * Outbound port for session authentication.
 *
 * The Application layer decides *when* to authenticate and what to record; the
 * framework decides how sessions work.
 */
interface Authenticator
{
    /** @return UserId|null the authenticated user, or null if the credentials were rejected */
    public function attempt(Email $email, string $password, bool $remember = false): ?UserId;

    /** Invalidate the session and rotate its token. */
    public function logout(): void;

    public function currentUserId(): ?UserId;

    /** True when the account exists but is deactivated — reported as a distinct outcome. */
    public function isDeactivated(Email $email): bool;
}
