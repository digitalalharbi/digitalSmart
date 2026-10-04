<?php

declare(strict_types=1);

namespace App\Application\Audit\Query;

use App\Application\Audit\Contract\AuditReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Access\Permission;

final readonly class ListAuditEntries
{
    public function __construct(
        private AuditReadModel $entries,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /**
     * @param  list<string>  $actions
     * @return Paginated<\App\Application\Audit\DTO\AuditEntryView>
     */
    public function __invoke(
        ?string $search,
        array $actions,
        ?string $actorId,
        ?bool $securityOnly,
        Pagination $pagination,
    ): Paginated {
        $this->guard->ensure(Permission::AuditView);

        // Always scoped to the active workspace: one tenant's trail is never
        // visible from another, whatever the caller's role.
        return $this->entries->paginate(
            $this->actor->workspaceId(),
            $search,
            $actions,
            $actorId,
            $securityOnly,
            $pagination,
        );
    }
}
