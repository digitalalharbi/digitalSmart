<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

use App\Application\Identity\Contract\Authenticator;
use App\Application\Identity\Exception\PasswordRejected;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Change your own password.
 *
 * `AuditAction::UserPasswordChanged` has existed since Phase 1 with nothing recording it, and
 * `bootstrap/app.php` has been scrubbing `current_password` out of logs for just as long: the audit vocabulary
 * and the logging policy both described an operation the product could not perform. A product with login
 * throttling, failed-attempt auditing and a seeded demo password that cannot be changed is not finished.
 *
 * **No permission check.** This is the one write in the product that is nobody else's business: a password
 * belongs to the person, not to a role, and a media buyer must be able to change theirs without
 * `workspaces.update`. The actor context is the authorisation — there is no `userId` parameter, so there is no
 * shape in which this changes somebody else's password.
 *
 * **The current password is required**, and that is not ceremony. A session can be left open on a shared
 * machine, and without the check whoever found it could lock the owner out of their own account.
 *
 * **Every other session ends.** Somebody changes their password *because* they think it is known — a shared
 * laptop, a phone left on a desk, a colleague who has gone — so leaving the other sessions signed in would mean
 * the change achieved nothing. The caller's own session survives, because signing somebody out of the screen
 * they are looking at reads as a failure.
 */
final readonly class ChangePassword
{
    /**
     * The shortest password this product will store.
     *
     * Twelve, which is longer than the eight most frameworks default to and shorter than a rule people work
     * around by writing it down. There is no composition requirement — no "one capital and one symbol" — because
     * those push people towards `Password1!` and a long passphrase beats a short mangled word.
     */
    public const int MINIMUM_LENGTH = 12;

    public function __construct(
        private Authenticator $authenticator,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $currentPassword, string $newPassword): void
    {
        $userId = $this->actor->userId();

        if (! $this->authenticator->confirmPassword($userId, $currentPassword)) {
            /*
             * Recorded as a failure, like a rejected sign-in: repeated attempts against a live session is a
             * signal somebody is sitting at a machine that is not theirs, and the absence of that record is the
             * gap.
             */
            $this->audit->record(new AuditEntry(
                workspaceId: $this->actor->workspaceId(),
                actorId: $userId,
                action: AuditAction::UserLoginFailed,
                auditableType: 'user',
                auditableId: $userId->value,
                summary: 'Wrong current password given when changing a password.',
            ));

            throw PasswordRejected::currentPasswordWrong();
        }

        if (mb_strlen($newPassword) < self::MINIMUM_LENGTH) {
            throw PasswordRejected::tooShort(self::MINIMUM_LENGTH);
        }

        if ($newPassword === $currentPassword) {
            /*
             * Refused rather than accepted as a no-op. Somebody changing a password they believe is known has to
             * end up with a different one, and "saved" over the same password would be a false assurance.
             */
            throw PasswordRejected::unchanged();
        }

        $this->authenticator->replacePassword($userId, $newPassword);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $userId,
            action: AuditAction::UserPasswordChanged,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: 'Changed their own password.',
            /* Never the password, never its hash, never its length. The fact and the time are the record. */
        ));
    }
}
