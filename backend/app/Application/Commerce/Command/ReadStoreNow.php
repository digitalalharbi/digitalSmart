<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\StoreSyncResult;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Read one store now, because somebody asked.
 *
 * `SyncStore` is the reconciliation read — written, tested, and reachable from nothing: no route, no job, no
 * command. Webhooks were the only way an order could arrive, which leaves a client's revenue quietly short for
 * every window in which a delivery was dropped, a deploy landed, or the platform had an incident; and a newly
 * connected store had no history at all.
 *
 * This is the request path: who may ask, which stores they can see, and then `ReadStore` for the work.
 */
final readonly class ReadStoreNow
{
    public function __construct(
        private StoreDirectory $stores,
        private ReadStore $read,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $storeId): StoreSyncResult
    {
        /*
         * `workspaces.update`, not `analytics.view`. Reading a store spends the workspace's credentials on
         * somebody else's API and writes to this product's tables; looking at the figures afterwards is the
         * lesser permission.
         */
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();

        /*
         * Resolved here as well as inside `ReadStore`, and for a different reason: this one narrows by project
         * visibility, so a store belonging to a client the asker cannot see is a `404` rather than a read.
         */
        $store = $this->stores->find(
            $workspaceId->value,
            $this->visibility->visibleProjectIds(),
            $storeId,
        ) ?? throw ResourceNotFound::of('Store');

        return ($this->read)($workspaceId, $store->id, $this->actor->userId());
    }
}
