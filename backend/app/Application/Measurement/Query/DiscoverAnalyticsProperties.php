<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry;
use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Every property the workspace's connection can read.
 *
 * Deliberately unfiltered. The person choosing which property measures which client needs to
 * see what the account actually holds — including the properties belonging to clients the agency
 * no longer works with, which is exactly the list somebody has to look at carefully rather than
 * one this product should prune on their behalf.
 *
 * Read-only and writes nothing. Registering a source is a separate, audited act.
 */
final readonly class DiscoverAnalyticsProperties
{
    public function __construct(
        private MeasurementSyncAdapterRegistry $adapters,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<DiscoveredProperty> */
    public function __invoke(Provider $provider): array
    {
        /*
         * `manage` rather than `view`: this reaches out to the provider on the workspace's
         * credentials, and reading somebody's whole analytics estate is a setup action rather
         * than a reporting one.
         */
        $this->guard->ensure(Permission::TrackingManage);

        if (! $this->adapters->supports($provider)) {
            return [];
        }

        $connection = $this->connections->findForProvider($this->actor->workspaceId(), $provider);

        if ($connection === null) {
            // Nothing connected. An empty list, not an error: the integrations page is where a
            // person fixes this, and it already says so.
            return [];
        }

        return $this->adapters->for($provider)->discoverProperties($connection->credentials());
    }
}
