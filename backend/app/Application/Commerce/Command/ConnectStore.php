<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Client\Contract\ClientVisibility;
use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\StoreView;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Commerce\Exception\StoreNotConnected;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Say that this merchant's orders are this client's revenue.
 *
 * The missing half of the commerce module. A workspace could connect Salla, the webhook endpoint could
 * authenticate its deliveries, the sync could read its orders and the analytics could report them — and nothing
 * could say *which client a merchant belongs to*, so every delivery was rejected as `unknown_store` and every
 * figure was empty. A store row could only be written by a seeder.
 *
 * **The store's details come from the provider, not from a form.** The name, currency, domain and timezone are
 * read back from discovery by the merchant id being connected: a typed currency would be the single most
 * expensive field in this product to get wrong, because every amount from that store is stored in it.
 *
 * **One store belongs to one client.** Registering the same merchant twice would count every order twice, and
 * the refusal names the client that already has it — a unique index sits behind the check for the race.
 */
final readonly class ConnectStore
{
    public function __construct(
        private StoreDirectory $stores,
        private CommerceSyncAdapterRegistry $adapters,
        private ProviderConnectionRepository $connections,
        private ClientVisibility $clients,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Provider $provider, string $externalId, string $clientId): StoreView
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();

        if ($provider->category() !== ProviderCategory::Commerce || ! $this->adapters->supports($provider)) {
            throw StoreNotConnected::providerCannotBeRead($provider->label());
        }

        /*
         * The client is checked for *visibility*, not merely existence. Somebody who cannot see a client has no
         * business deciding whose revenue a merchant's orders become, and a plain existence check would let
         * them name any client in the workspace.
         */
        if (! $this->clients->isVisible($workspaceId->value, $this->visibility->visibleProjectIds(), $clientId)) {
            throw ResourceNotFound::of('Client');
        }

        $connection = $this->connections->agencyConnection($workspaceId, $provider);

        if ($connection === null) {
            throw StoreNotConnected::providerIsNotConnected($provider->label());
        }

        $registeredTo = $this->stores->registeredClientName($workspaceId->value, $provider, $externalId);

        if ($registeredTo !== null) {
            throw StoreNotConnected::alreadyRegisteredTo($externalId, $registeredTo);
        }

        /*
         * Read back from the provider rather than trusted from the request. A merchant id the connection can
         * no longer see is a store nobody can read, and registering it would create a row whose every sync
         * fails for a reason the row itself cannot explain.
         */
        try {
            $discovered = $this->adapters->for($provider)->discoverStores($connection->credentials());
        } catch (ProviderNotConfigured $exception) {
            throw $exception;
        }

        $match = null;

        foreach ($discovered as $candidate) {
            if ($candidate->externalId === $externalId) {
                $match = $candidate;
                break;
            }
        }

        if ($match === null) {
            throw StoreNotConnected::notVisibleFromTheProvider($externalId);
        }

        $store = $this->stores->register(
            $workspaceId->value,
            $clientId,
            $provider,
            $match,
            $connection->id->value,
        );

        if ($store === null) {
            /* Lost the race against another request: the index refused it, and so does this. */
            throw StoreNotConnected::alreadyRegisteredTo(
                $externalId,
                $this->stores->registeredClientName($workspaceId->value, $provider, $externalId) ?? 'another client',
            );
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::StoreConnected,
            auditableType: 'store',
            auditableId: $store->id,
            summary: sprintf('Connected the store "%s" to %s.', $store->name, $store->clientName),
            changes: [
                'provider' => $provider->value,
                'external_id' => $store->externalId,
                'client_id' => $store->clientId,
                /* The currency, because every amount read from this store will be stored in it. */
                'currency' => $store->currency,
            ],
        ));

        return $store;
    }
}
