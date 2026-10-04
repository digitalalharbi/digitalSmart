<?php

declare(strict_types=1);

namespace App\Application\Project\Query;

use App\Application\Project\Contract\ProjectMemberRepository;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;

final readonly class ListProjectMembers
{
    public function __construct(
        private ProjectMemberRepository $members,
        private AuthorizationGuard $guard,
    ) {}

    /** @return list<array{user_id: string, name: string, email: string, role_slug: string, role_name: string}> */
    public function __invoke(ProjectId $projectId): array
    {
        $this->guard->ensureAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned);
        // Seeing who else is on a project requires being able to see it.
        $this->guard->ensureProjectVisible($projectId);

        return $this->members->listFor($projectId);
    }
}
