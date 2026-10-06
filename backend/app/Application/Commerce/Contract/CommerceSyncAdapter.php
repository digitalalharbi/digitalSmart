<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Application\Commerce\DTO\StoreRecord;
use App\Application\Shared\DTO\ProviderPage;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reading a store platform.
 *
 * The contract Salla and Zid both implement, written before either of them so that neither gets to
 * define it. The first platform integrated otherwise becomes the vocabulary the second has to be
 * bent into, and an agency reporting on one client per platform ends up comparing two different
 * meanings of "paid".
 *
 * What every implementation must honour:
 *
 *  - **Normalise the two state axes from the provider's own documented strings**, and carry those
 *    strings through untouched. A mapping will be disputed, and the provider's word for it has to
 *    still be on the record when it is.
 *  - **Money as integer minor units, with the currency.** Never a float, never an amount without
 *    its unit. A discount is a positive amount taken off, never a negative one added.
 *  - **Attribution is only what the order carried.** Build it with `OrderAttribution::from()` so
 *    every platform reaches the same verdict from the same facts, and never fill in a campaign from
 *    the hour an order arrived or from what was running that week.
 *  - **Null for what the provider does not report.** Never zero: "no price" and "free" are different
 *    facts, and so are "stock untracked" and "out of stock".
 *  - **Paginate with an opaque cursor** the caller stores and hands back.
 *  - **Be re-runnable over the same window without duplicating anything.**
 */
interface CommerceSyncAdapter
{
    /**
     * Every store this connection can read.
     *
     * @return list<StoreRecord>
     */
    public function discoverStores(Credentials $credentials): array;

    /**
     * Orders placed or updated in a window.
     *
     * Windowed on the provider's **updated** timestamp where the platform offers one, not on when
     * the order was placed: a month-old order that was refunded this morning has to come back, and a
     * sync keyed on placement would never see it again.
     *
     * @return ProviderPage<OrderRecord>
     */
    public function fetchOrders(
        Credentials $credentials,
        string $storeExternalId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): ProviderPage;

    /**
     * The catalogue.
     *
     * @return ProviderPage<ProductRecord>
     */
    public function fetchProducts(
        Credentials $credentials,
        string $storeExternalId,
        ?string $cursor = null,
    ): ProviderPage;

    /**
     * Customers.
     *
     * @return ProviderPage<CustomerRecord>
     */
    public function fetchCustomers(
        Credentials $credentials,
        string $storeExternalId,
        ?string $cursor = null,
    ): ProviderPage;

    /**
     * How far back to re-read on every sync.
     *
     * Orders keep changing after they are placed — paid, shipped, refunded, cancelled — so a sync
     * that only fetched new ones would freeze every order in the state it was first seen in.
     */
    public function restatementWindowDays(): int;

    /** Largest window the provider will answer in one request. */
    public function maxRangeDays(): int;
}
