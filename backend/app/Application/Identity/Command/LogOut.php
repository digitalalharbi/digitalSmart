<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

use App\Application\Identity\Contract\Authenticator;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Workspace\WorkspaceId;

final readonly class LogOut
{
    public function __construct(
        private Authenticator $authenticator,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(?WorkspaceId $workspaceId = null): void
    {
        $userId = $this->authenticator->currentUserId();

        if ($userId !== null) {
            $this->audit->record(new AuditEntry(
                workspaceId: $workspaceId,
                actorId: $userId,
                action: AuditAction::UserLoggedOut,
                auditableType: 'user',
                auditableId: $userId->value,
                summary: 'Signed out.',
            ));
        }

        $this->authenticator->logout();
    }
}
