<?php

declare(strict_types=1);

namespace App\Application\Workspace\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Contract\AssignedWorkRepository;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Application\Workspace\DTO\HeldWork;
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
 *
 * **Their open work is released, and the counts are returned.** Four tables carry an assignment, and leaving a
 * removed person's name on any of them would be the quiet creation of orphans: a board showing work owned by
 * somebody who cannot open it, a workload chart counting a person who has gone, and a client request waiting on
 * a reply from nobody. Releasing is not a tidy-up — it makes the boards true again, and the work appears as
 * unassigned, which is both the fact and the thing somebody has to act on.
 *
 * **Finished work keeps their name.** A task somebody completed is a record of who completed it; rewriting that
 * to say nobody did would be falsifying history to tidy a column.
 *
 * **Suspension does not release anything**, deliberately. A suspended member is expected back and their work
 * waits for them; the directory shows the status beside their name. Removal is the irreversible one, which is
 * why it is the one that hands the work back.
 */
final readonly class RemoveMember
{
    public function __construct(
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private AssignedWorkRepository $work,
    ) {}

    public function __invoke(UserId $userId): HeldWork
    {
        $this->guard->ensure(Permission::WorkspacesManageMembers);

        $workspaceId = $this->actor->workspaceId();

        $membership = $this->memberships->find($workspaceId, $userId)
            ?? throw ResourceNotFound::of('Member');

        if ($membership->roleSlug === SystemRole::Owner->value
            && $this->memberships->countActiveWithRole($workspaceId, SystemRole::Owner->value) <= 1) {
            throw LastOwnerProtected::cannotSuspend();
        }

        if (! $this->memberships->remove($workspaceId, $userId, $this->actor->userId())) {
            throw ResourceNotFound::of('Member');
        }

        /* After the membership goes, so a concurrent assignment cannot land between the two. */
        $released = $this->work->release($workspaceId, $userId);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::WorkspaceMemberRemoved,
            auditableType: 'user',
            auditableId: $userId->value,
            summary: $released->isEmpty()
                ? 'Removed a member from the workspace.'
                : sprintf(
                    'Removed a member from the workspace and released %d open item(s) from their name.',
                    $released->total(),
                ),
            /*
             * The counts per kind, because "what happened to their work" is asked months later and the answer
             * has to be in the record rather than inferred from a board that has since moved on.
             */
            changes: ['role' => $membership->roleSlug, 'released' => $released->toArray()],
        ));

        return $released;
    }
}
