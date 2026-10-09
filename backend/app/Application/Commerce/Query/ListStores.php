<?php

declare(strict_types=1);

namespace App\Application\Commerce\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\StoreView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * Which stores this workspace reads, and when each was last read.
 *
 * Behind `analytics.view`, the permission the revenue figures themselves need: "where does this number come
 * from, and how old is it" is part of reading the number. Narrowed by the same visibility rule as the figures,
 * so the list and the totals beside it cannot disagree about who can see what.
 */
final readonly class ListStores
{
    public function __construct(
        private StoreDirectory $stores,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<StoreView> */
    public function __invoke(?string $clientId = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        return $this->stores->forWorkspace(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
        );
    }
}
