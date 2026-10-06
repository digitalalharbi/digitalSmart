<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * Writing normalised commerce records.
 *
 * Every method upserts on the natural key the database already enforces, because every sync re-reads
 * a window and the same order arrives repeatedly. An insert would multiply a client's revenue by the
 * number of times the sync had run, and the result would look exactly like growth.
 */
interface CommerceStore
{
    /**
     * Upsert orders, their items, refunds and shipments.
     *
     * Items are **replaced** rather than merged: the lines on an order are the whole truth about what
     * was sold, and a line somebody removed in an amended order has to stop counting.
     *
     * @param  list<OrderRecord>  $orders
     * @return int orders written
     */
    public function upsertOrders(
        string $workspaceId,
        string $storeId,
        array $orders,
        RevenueSource $revenueSource,
        MetricSource $source,
    ): int;

    /**
     * @param  list<ProductRecord>  $products
     * @return int products written
     */
    public function upsertProducts(string $workspaceId, string $storeId, array $products): int;

    /**
     * @param  list<CustomerRecord>  $customers
     * @return int customers written
     */
    public function upsertCustomers(string $workspaceId, string $storeId, array $customers): int;

    /**
     * Recompute a customer's lifetime figures from the orders actually stored.
     *
     * Derived rather than taken from the provider, because the provider's own total includes orders
     * this workspace may not have read and counts refunds differently. A figure a reader can
     * reconcile against the orders in front of them is worth more than one that is merely larger.
     */
    public function recalculateCustomerTotals(string $workspaceId, string $storeId): void;

    /** Record that the store was read, and how far through its orders. */
    public function markSynced(
        string $workspaceId,
        string $storeId,
        DateTimeImmutable $syncedAt,
        ?DateTimeImmutable $ordersSyncedThrough = null,
    ): void;
}
