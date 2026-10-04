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
use App\Domain\Workspace\MembershipStatus;

final readonly class ChangeMemberStatus
{
    public function __construct(
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(UserId $userId, MembershipStatus $status): void
    {
        $this->guard->ensure(Permission::UsersDeactivate);

        $workspaceId = $this->actor->workspaceId();

        $membership = $this->memberships->find($workspaceId, $userId)
            ?? throw ResourceNotFound::of('Member');

        if (! $status->grantsAccess()
            && $membership->roleSlug === SystemRole::Owner->value
            && $this->memberships->countActiveWithRole($workspaceId, SystemRole::Owner->value) <= 1) {
            throw LastOwnerProtected::cannotSuspend();
        }

        $this->memberships->changeStatus($workspaceId, $userId, $status);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::WorkspaceMemberSuspended,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: sprintf('Set a member\'s status to %s.', $status->value),
            changes: ['from' => $membership->status->value, 'to' => $status->value],
        ));
    }
}
