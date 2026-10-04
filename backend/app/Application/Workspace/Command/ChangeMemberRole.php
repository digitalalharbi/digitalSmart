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

final readonly class ChangeMemberRole
{
    public function __construct(
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(UserId $userId, string $roleSlug): void
    {
        $this->guard->ensure(Permission::UsersUpdate);

        $workspaceId = $this->actor->workspaceId();

        $membership = $this->memberships->find($workspaceId, $userId)
            ?? throw ResourceNotFound::of('Member');

        $roleId = $this->memberships->findRoleIdBySlug($workspaceId, $roleSlug)
            ?? throw ResourceNotFound::of('Role');

        // Demoting the last owner would leave the workspace unadministrable.
        if ($membership->roleSlug === SystemRole::Owner->value
            && $roleSlug !== SystemRole::Owner->value
            && $this->memberships->countActiveWithRole($workspaceId, SystemRole::Owner->value) <= 1) {
            throw LastOwnerProtected::cannotDemote();
        }

        $this->memberships->changeRole($workspaceId, $userId, $roleId);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::WorkspaceMemberRoleChanged,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: sprintf('Changed a member\'s role from %s to %s.', $membership->roleSlug, $roleSlug),
            changes: ['from' => $membership->roleSlug, 'to' => $roleSlug],
        ));
    }
}
