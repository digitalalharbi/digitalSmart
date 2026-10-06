<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Commerce\Contract\StoreLocator;
use App\Application\Commerce\DTO\StoreIdentity;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Store;

final readonly class EloquentStoreLocator implements StoreLocator
{
    /**
     * Not scoped to a workspace, because a webhook arrives without one.
     *
     * Every match is returned so the caller can tell the candidates apart by whose secret verifies the
     * signature. See the contract for why the first match is not good enough.
     *
     * @return list<StoreIdentity>
     */
    public function findAllByExternalId(Provider $provider, string $externalId): array
    {
        $stores = Store::query()
            ->where('provider', $provider->value)
            ->where('external_id', $externalId)
            // Oldest first, so the trace left for an unverifiable delivery is stable between retries.
            ->orderBy('created_at')
            ->get();

        return array_values($stores->map(static fn (Store $store): StoreIdentity => new StoreIdentity(
            id: (string) $store->getKey(),
            workspaceId: (string) $store->getAttribute('workspace_id'),
            clientId: (string) $store->getAttribute('client_id'),
            externalId: (string) $store->getAttribute('external_id'),
            currency: (string) $store->getAttribute('currency'),
        ))->all());
    }
}
