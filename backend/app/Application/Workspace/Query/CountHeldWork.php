<?php

declare(strict_types=1);

namespace App\Application\Workspace\Query;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Contract\AssignedWorkRepository;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Application\Workspace\DTO\HeldWork;
use App\Domain\Access\Permission;
use App\Domain\Identity\UserId;

/**
 * What somebody still holds, asked before deciding to remove them.
 *
 * Removal releases their open work, which is the right behaviour and a consequence the person deciding should
 * see first: "remove" over a name holding eleven open items and "remove" over a name holding none are different
 * decisions, and a product that renders them identically has hidden the difference.
 *
 * Behind the same permission as the removal itself. The figure says how loaded a named colleague is, which is a
 * manager's business and nobody else's.
 */
final readonly class CountHeldWork
{
    public function __construct(
        private AssignedWorkRepository $work,
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(UserId $userId): HeldWork
    {
        $this->guard->ensure(Permission::WorkspacesManageMembers);

        $workspaceId = $this->actor->workspaceId();

        /* Asked about somebody who is not here: not found, rather than a confident zero about a stranger. */
        $this->memberships->find($workspaceId, $userId) ?? throw ResourceNotFound::of('Member');

        return $this->work->heldBy($workspaceId, $userId);
    }
}
