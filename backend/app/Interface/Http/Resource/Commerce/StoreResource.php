<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Commerce;

use App\Application\Commerce\DTO\ConnectableStore;
use App\Application\Commerce\DTO\StoreSyncResult;
use App\Application\Commerce\DTO\StoreView;

/** The stores a workspace reads, the ones it could read, and what a read brought back. */
final readonly class StoreResource
{
    /** @return array<string, mixed> */
    public static function store(StoreView $store): array
    {
        return [
            'id' => $store->id,
            'client_id' => $store->clientId,
            'client_name' => $store->clientName,
            'provider' => $store->provider->value,
            'provider_label' => $store->provider->label(),
            'external_id' => $store->externalId,
            'name' => $store->name,
            'domain' => $store->domain,
            /* Every amount read from this store is in it, so it travels with the store rather than per figure. */
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'is_active' => $store->isActive,
            /*
             * Both, and they are different facts: when somebody last asked, and how far through the orders the
             * answer got. A store that is failing has the first and not the second.
             */
            'last_synced_at' => $store->lastSyncedAt,
            'orders_synced_through' => $store->ordersSyncedThrough,
            /* Whether anything can go and read it: a provider with no sync adapter cannot be read at all. */
            'is_readable' => $store->isReadable,
        ];
    }

    /** @return array<string, mixed> */
    public static function connectable(ConnectableStore $store): array
    {
        return [
            'provider' => $store->provider->value,
            'provider_label' => $store->provider->label(),
            'external_id' => $store->externalId,
            'name' => $store->name,
            'currency' => $store->currency,
            'domain' => $store->domain,
            'timezone' => $store->timezone,
            /*
             * Published rather than filtered out. A merchant missing from the list reads as "the connection
             * cannot see it", and somebody then goes looking at the platform for a problem that is not there.
             */
            'is_registered' => $store->isRegistered,
            'registered_to_client_name' => $store->registeredToClientName,
        ];
    }

    /** @return array<string, mixed> */
    public static function readResult(StoreSyncResult $result): array
    {
        return [
            'status' => $result->status->value,
            'orders_written' => $result->ordersWritten,
            'products_written' => $result->productsWritten,
            'customers_written' => $result->customersWritten,
            'api_calls' => $result->apiCalls,
            /* The honesty label: sandbox fixtures are never reported as a live read. */
            'source' => $result->source->value,
            'failure_message' => $result->failureMessage,
        ];
    }
}
