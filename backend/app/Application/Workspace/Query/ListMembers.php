<?php

declare(strict_types=1);

namespace App\Application\Workspace\Query;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Application\Workspace\Contract\MemberReadModel;
use App\Domain\Access\Permission;

final readonly class ListMembers
{
    public function __construct(
        private MemberReadModel $members,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return Paginated<\App\Application\Workspace\DTO\MemberView> */
    public function __invoke(
        ?string $search,
        ?string $roleSlug,
        ?string $status,
        Pagination $pagination,
    ): Paginated {
        $this->guard->ensure(Permission::UsersView);

        return $this->members->paginate(
            $this->actor->workspaceId(),
            $search,
            $roleSlug,
            $status,
            $pagination,
        );
    }
}
