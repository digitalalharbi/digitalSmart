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
use App\Infrastructure\Commerce\Mapper\ZidMapper;
use App\Infrastructure\Commerce\Sandbox\CommerceFixtures;
use DateTimeImmutable;

/**
 * Zid, read through the API v1.
 *
 * Built against the published contracts at `https://api.zid.sa/v1/managers/store/`:
 *
 *  - `GET …/orders?payload_type=detailed&page=1&per_page=15` — scope `orders.read`
 *  - `GET …/products` — scope `products.read`
 *  - `GET …/customers` — scope `customers.read`
 *
 * **Two tokens, not one.** Zid authenticates with an `Authorization` header carrying the app's token
 * *and* an `X-Manager-Token` header carrying the store's. Unlike every other provider here, a single
 * bearer token is not enough — which is worth knowing before somebody wires the transport and spends an
 * afternoon on 401s.
 *
 * **`payload_type` must be `detailed` or there are no products.** It defaults to `simple`, which omits
 * them entirely. A sync left on the default would read every order and no line items, and the result
 * would be a product report that is silently, permanently empty.
 *
 * `Accept-Language` decides whether localised fields come back Arabic or English. The mapper handles
 * both shapes and prefers Arabic, because these are Saudi stores and the Arabic name is the one a
 * merchant's team will recognise in a report.
 *
 * BLOCKED_EXTERNAL: needs a Zid Partner app, a merchant who installs it, and the OAuth handshake. Real
 * credentials fail and name what is missing; the literal credential `sandbox` reads fixtures.
 */
final readonly class ZidSyncAdapter implements CommerceSyncAdapter
{
    /** Zid's documented page size for the orders list. */
    public const int PER_PAGE = 15;

    /** The query value without which the response carries no products at all. */
    public const string PAYLOAD_DETAILED = 'detailed';

    private const string FIXTURE_STORE = 'store';

    private const string FIXTURE_ORDERS = 'orders';

    private const string FIXTURE_PRODUCTS = 'products';

    private const string FIXTURE_CUSTOMERS = 'customers';

    public function __construct(
        private CommerceFixtures $fixtures,
        private ZidMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::Zid;
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

            // Windowed on the updated time where Zid reported one: a month-old order reversed this
            // morning has to come back, and a filter keyed on placement would never see it again.
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

    /**
     * Customers.
     *
     * The customers endpoint returns real details; the masking that applies to marketplace *orders* is
     * a property of the order, not of the customer record, so nothing is dropped here.
     *
     * @return ProviderPage<CustomerRecord>
     */
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

    public function restatementWindowDays(): int
    {
        /*
         * A fortnight, as for Salla and for the same reasons: cash on delivery settles days later, a
         * reversal arrives weeks on, and an order frozen in the state it was first seen in is worse than
         * a few extra requests.
         */
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
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }
}
