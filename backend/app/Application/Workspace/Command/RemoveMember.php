<?php

declare(strict_types=1);

namespace App\Application\Workspace\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Application\Workspace\Exception\LastOwnerProtected;
use App\Domain\Access\Permission;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;

/**
 * Remove someone from a workspace.
 *
 * The user account survives — their audit trail must remain attributable — but
 * they lose all access to this tenant.
 */
final readonly class RemoveMember
{
    public function __construct(
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(UserId $userId): void
    {
        $this->guard->ensure(Permission::WorkspacesManageMembers);

        $workspaceId = $this->actor->workspaceId();

        $membership = $this->memberships->find($workspaceId, $userId)
            ?? throw ResourceNotFound::of('Member');

        if ($membership->roleSlug === SystemRole::Owner->value
            && $this->memberships->countActiveWithRole($workspaceId, SystemRole::Owner->value) <= 1) {
            throw LastOwnerProtected::cannotSuspend();
        }

        if (! $this->memberships->remove($workspaceId, $userId)) {
            throw ResourceNotFound::of('Member');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::WorkspaceMemberSuspended,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: 'Removed a member from the workspace.',
            changes: ['role' => $membership->roleSlug],
        ));
    }
}
