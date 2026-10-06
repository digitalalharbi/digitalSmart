<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Adapter;

use App\Application\Commerce\Contract\CommerceSyncAdapter;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Application\Commerce\DTO\StoreRecord;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Shared\DTO\ProviderPage;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Infrastructure\Commerce\Mapper\SallaMapper;
use App\Infrastructure\Commerce\Sandbox\CommerceFixtures;
use DateTimeImmutable;

/**
 * Salla, read through the Merchant API v2.
 *
 * Built against the published contracts:
 *
 *  - `GET https://api.salla.dev/admin/v2/orders` — scope `orders.read`
 *  - `GET https://api.salla.dev/admin/v2/orders/{id}` — the amount breakdown and shipments
 *  - `GET https://api.salla.dev/admin/v2/products` — scope `products.read`
 *  - `GET https://api.salla.dev/admin/v2/customers` — scope `customers.read`
 *
 * OAuth runs at `https://accounts.salla.sa/oauth2/auth` and `…/oauth2/token`, with `offline_access`
 * required to be issued a refresh token at all.
 *
 * **Two Salla-specific rules are encoded here, and both are easy to get wrong.**
 *
 * *Pages must be requested in order.* Salla documents that a page out of sequence returns an empty
 * response, and that sequential requests are cached for fifteen minutes. A sync that parallelised its
 * pages, or resumed from page four after a crash, would silently read nothing and report success — so
 * the cursor carries the page number and the adapter refuses to skip.
 *
 * *The order list has no amount breakdown.* It returns a total and nothing else. Reading a month of a
 * busy store therefore gives totals, with the subtotal, shipping and tax left **null** — which means
 * "not reported", not zero. A caller that needs the breakdown fetches the details endpoint, one
 * request per order, and should decide deliberately that it is worth the quota.
 *
 * BLOCKED_EXTERNAL: needs a Salla Partner app, a merchant who installs it, and the OAuth handshake.
 * With real credentials every call here fails and names what is missing; with the literal credential
 * `sandbox` it reads response-shaped fixtures. No path invents an order.
 */
final readonly class SallaSyncAdapter implements CommerceSyncAdapter
{
    /** Salla's documented maximum page size, and the one it recommends. */
    public const int PER_PAGE = 30;

    private const string FIXTURE_STORE = 'store';

    private const string FIXTURE_ORDERS = 'orders';

    private const string FIXTURE_PRODUCTS = 'products';

    private const string FIXTURE_CUSTOMERS = 'customers';

    public function __construct(
        private CommerceFixtures $fixtures,
        private SallaMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::Salla;
    }

    /** @return list<StoreRecord> */
    public function discoverStores(Credentials $credentials): array
    {
        $this->assertConfigured($credentials);

        $stores = [];

        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_STORE) as $payload) {
            $store = $this->mapper->store($payload);

            if ($store->externalId !== '') {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    /** @return ProviderPage<OrderRecord> */
    public function fetchOrders(
        Credentials $credentials,
        string $storeExternalId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): ProviderPage {
        $this->assertConfigured($credentials);

        $page = $this->fixtures->page($this->provider(), self::FIXTURE_ORDERS, $cursor);
        $orders = [];

        foreach ($page['items'] as $payload) {
            $order = $this->mapper->order($payload);

            if ($order->externalId === '') {
                continue;
            }

            /*
             * The window the real request applies with `from_date` and `to_date`. Filtered on the
             * provider's **updated** time where it reported one, falling back to the placement date:
             * a month-old order refunded this morning has to come back, and a filter keyed only on
             * placement would never see it again.
             */
            $at = $order->providerUpdatedAt ?? $order->placedAt;

            if ($at >= $since && $at <= $until) {
                $orders[] = $order;
            }
        }

        return new ProviderPage(items: $orders, nextCursor: $page['nextCursor']);
    }

    /** @return ProviderPage<ProductRecord> */
    public function fetchProducts(
        Credentials $credentials,
        string $storeExternalId,
        ?string $cursor = null,
    ): ProviderPage {
        $this->assertConfigured($credentials);

        $page = $this->fixtures->page($this->provider(), self::FIXTURE_PRODUCTS, $cursor);

        $products = array_values(array_filter(
            array_map(fn (array $payload): ProductRecord => $this->mapper->product($payload), $page['items']),
            static fn (ProductRecord $product): bool => $product->externalId !== '',
        ));

        return new ProviderPage(items: $products, nextCursor: $page['nextCursor']);
    }

    /** @return ProviderPage<CustomerRecord> */
    public function fetchCustomers(
        Credentials $credentials,
        string $storeExternalId,
        ?string $cursor = null,
    ): ProviderPage {
        $this->assertConfigured($credentials);

        $page = $this->fixtures->page($this->provider(), self::FIXTURE_CUSTOMERS, $cursor);

        $customers = array_values(array_filter(
            array_map(fn (array $payload): CustomerRecord => $this->mapper->customer($payload), $page['items']),
            static fn (CustomerRecord $customer): bool => $customer->externalId !== '',
        ));

        return new ProviderPage(items: $customers, nextCursor: $page['nextCursor']);
    }

    /**
     * A fortnight of re-reading on every sync.
     *
     * Salla orders change long after they are placed: a cash-on-delivery order is paid at the door
     * days later, a return arrives a fortnight on, and a refund can follow weeks after that. A sync
     * that only fetched new orders would freeze every one of them in the state it was first seen in.
     */
    public function restatementWindowDays(): int
    {
        return 14;
    }

    public function maxRangeDays(): int
    {
        return 92;
    }

    /**
     * @throws ProviderNotConfigured when credentials are missing, or real and therefore unusable
     */
    private function assertConfigured(Credentials $credentials): void
    {
        $required = $this->provider()->requiredCredentialKeys();
        $missing = $credentials->missing($required);

        if ($missing !== []) {
            throw ProviderNotConfigured::for($this->provider(), $missing);
        }

        if (! $credentials->isSandbox($required)) {
            /*
             * Real credentials, no installed Partner app. Failing loudly is the point: a silent fake
             * success would put invented orders — invented *revenue* — in front of a client.
             */
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }
}
