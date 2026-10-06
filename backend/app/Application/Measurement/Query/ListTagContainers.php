<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Every tag container the workspace's connection can read.
 *
 * A use case rather than a controller reaching for the adapter, because the permission check is
 * the point: this spends the workspace's credentials on somebody else's API, and that is not a
 * thing a route should be able to do by virtue of being routed.
 *
 * Listing is deliberately separate from assessing. Listing containers is one cheap call; reading
 * each one's published version is a call per container, and doing that eagerly would make a page
 * that exists to let somebody pick a container slower the more containers they have.
 */
final readonly class ListTagContainers
{
    public function __construct(
        private TagContainerReadAdapter $containers,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<DiscoveredContainer> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $connection = $this->connections->findForProvider(
            $this->actor->workspaceId(),
            Provider::GoogleTagManager,
        );

        if ($connection === null) {
            // Nothing connected. Empty, not an error: the integrations page is where that is fixed.
            return [];
        }

        return $this->containers->discoverContainers($connection->credentials());
    }
}
