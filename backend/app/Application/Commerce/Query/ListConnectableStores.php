<?php

declare(strict_types=1);

namespace App\Application\Commerce\Query;

use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\ConnectableStore;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Every store the workspace's commerce connections can read.
 *
 * `workspaces.update`, the same permission the other provider discovery needs: this spends the workspace's
 * credentials on somebody else's API, which is not something a route should be able to do by virtue of being
 * routed.
 *
 * **A provider that is not connected contributes nothing and is not an error.** Nothing connected is the
 * ordinary state of a checkout, and the integrations page is where it is fixed — so this answers with an empty
 * list rather than a failure the reader cannot act on from the panel that asked.
 *
 * **A provider that fails is skipped, not fatal.** One unreachable platform must not empty the list of the
 * other's stores: an agency with Salla working and Zid rate-limited still has a Salla store to connect.
 */
final readonly class ListConnectableStores
{
    public function __construct(
        private CommerceSyncAdapterRegistry $adapters,
        private StoreDirectory $stores,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ConnectableStore> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();
        $found = [];

        foreach (Provider::cases() as $provider) {
            if ($provider->category() !== ProviderCategory::Commerce || ! $this->adapters->supports($provider)) {
                continue;
            }

            $connection = $this->connections->agencyConnection($workspaceId, $provider);

            if ($connection === null) {
                continue;
            }

            try {
                $discovered = $this->adapters->for($provider)->discoverStores($connection->credentials());
            } catch (ProviderNotConfigured|ProviderRequestFailed|ProviderUnavailable) {
                continue;
            }

            foreach ($discovered as $store) {
                $registeredTo = $this->stores->registeredClientName(
                    $workspaceId->value,
                    $provider,
                    $store->externalId,
                );

                $found[] = new ConnectableStore(
                    provider: $provider,
                    externalId: $store->externalId,
                    name: $store->name,
                    currency: $store->currency,
                    domain: $store->domain,
                    timezone: $store->timezone,
                    isRegistered: $registeredTo !== null,
                    registeredToClientName: $registeredTo,
                );
            }
        }

        return $found;
    }
}
