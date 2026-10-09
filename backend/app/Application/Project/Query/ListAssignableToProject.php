<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;

/**
 * Who could be given access to this project.
 *
 * `ProjectsManageMembers`, the same permission that does the assigning: the list exists to be acted on, and
 * offering it to somebody who cannot act would be showing them the agency's staff list for nothing.
 *
 * Deliberately **not** `OwnerDirectory`. That answers "who can already see this thing", for deciding who may own
 * a piece of work; this is almost its inverse — the people worth offering are the ones who cannot see the
 * project yet.
 */
final readonly class ListAssignableToProject
{
    public function __construct(
        private ProjectMemberRepository $members,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<array{user_id: string, name: string, role_slug: string, role_name: string}> */
    public function __invoke(ProjectId $projectId): array
    {
        $this->guard->ensure(Permission::ProjectsManageMembers);
        /* Seeing who could be added requires being able to see the project. */
        $this->guard->ensureProjectVisible($projectId);

        return $this->members->assignableTo($this->actor->workspaceId(), $projectId);
    }
}
