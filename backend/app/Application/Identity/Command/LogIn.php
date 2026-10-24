<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Identity\Contract\Authenticator;
use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Identity\Exception\AuthenticationFailed;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\Email;
use DateTimeImmutable;

final readonly class LogIn
{
    public function __construct(
        private Authenticator $authenticator,
        private IdentityReadModel $identities,
        private AuditRecorder $audit,
        private AccessRequestRepository $accessRequests,
    ) {}

    public function __invoke(Email $email, string $password, bool $remember = false): AuthenticatedUser
    {
        $userId = $this->authenticator->attempt($email, $password, $remember);

        if ($userId === null) {
            // Failed attempts are recorded: repeated failures against one
            // account are a signal, and the absence of that record is a gap.
            $this->audit->record(new AuditEntry(
                workspaceId: null,
                actorId: null,
                action: AuditAction::UserLoginFailed,
                summary: 'Failed sign-in attempt.',
                context: ['email' => $email->value],
            ));

            throw $this->authenticator->isDeactivated($email)
                ? AuthenticationFailed::accountDeactivated()
                : AuthenticationFailed::invalidCredentials();
        }

        $workspaceId = $this->identities->defaultWorkspaceId($userId);

        if ($workspaceId === null) {
            // A user with no workspace cannot do anything; ending the session
            // here avoids a half-authenticated state the UI cannot represent.
            $this->authenticator->logout();

            throw AuthenticationFailed::noWorkspace();
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $userId,
            action: AuditAction::UserLoggedIn,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: 'Signed in.',
        ));

        /* If this person arrived through an invitation, this is the first time they have used the account it made. */
        $this->accessRequests->recordFirstLogin($userId->value, new DateTimeImmutable);

        return $this->identities->authenticatedUser($userId, $workspaceId)
            ?? throw AuthenticationFailed::invalidCredentials();
    }
}
