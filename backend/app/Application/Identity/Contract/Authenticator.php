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

    /**
     * Whether this is the signed-in user's own password.
     *
     * Its own method rather than reusing `attempt()`, and the difference is the point: `attempt()` signs somebody
     * in and regenerates the session, which is exactly wrong here. Confirming a password in the middle of a
     * session must not touch the session, and it must not record a sign-in that did not happen.
     */
    public function confirmPassword(UserId $userId, string $password): bool;

    /**
     * Replace the password, and end every other session that was using the old one.
     *
     * The second half is not a nicety. Somebody changes their password *because* they think it is known — a
     * shared laptop, a phone left on a desk, a departed colleague — and leaving the other sessions signed in
     * means the change achieved nothing. The caller's own session survives, because signing somebody out of the
     * screen they are looking at reads as a failure.
     */
    public function replacePassword(UserId $userId, string $password): void;

    /**
     * Whether this person is still using a password somebody else chose for them.
     *
     * On this port rather than read off a model, because the only caller is middleware in the interface layer —
     * and the architecture test is right that the interface may not reach into infrastructure for it. The
     * question is "may this request proceed", which is an application question with an infrastructure answer.
     */
    public function mustChoosePassword(UserId $userId): bool;
}
