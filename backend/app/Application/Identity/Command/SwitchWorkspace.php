<?php

declare(strict_types=1);

namespace App\Application\Identity\Command;

use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Change the session's active workspace.
 *
 * Membership is re-checked here rather than trusted from the request: the
 * workspace id arrives from the client, and it is the key every subsequent
 * query is scoped by.
 */
final readonly class SwitchWorkspace
{
    public function __construct(
        private IdentityReadModel $identities,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(UserId $userId, WorkspaceId $workspaceId, ?WorkspaceId $from = null): AuthenticatedUser
    {
        if (! $this->identities->isMemberOf($userId, $workspaceId)) {
            throw ResourceNotFound::of('Workspace');
        }

        $user = $this->identities->authenticatedUser($userId, $workspaceId)
            ?? throw ResourceNotFound::of('Workspace');

        /*
         * Recorded in the workspace being entered, the one whose data the session can now read. The workspace left is named by
         * id only: an entry in one tenant's trail must not describe another tenant.
         */
        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $userId,
            action: AuditAction::WorkspaceSwitched,
            auditableType: 'workspace',
            auditableId: $workspaceId->value,
            summary: 'Switched into this workspace.',
            changes: ['from' => $from?->value, 'to' => $workspaceId->value],
        ));

        return $user;
    }
}
