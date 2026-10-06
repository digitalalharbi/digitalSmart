<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\Contract\CommerceSyncAdapter;
use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry;
use App\Application\Commerce\DTO\StoreSyncResult;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Read one store and write what it reported.
 *
 * The reconciliation sync. Webhooks are faster and they are not sufficient: one dropped delivery, one
 * deploy during a busy hour, one Salla incident, and a client's revenue is quietly short for that
 * window with nothing to indicate it. This is what makes the figures eventually correct regardless, so
 * it re-reads a window rather than trusting a high-water mark.
 *
 * The order matters. Customers and products are read before orders because an order line references
 * both, and a line read first would store a null where a product should be — losing which product a
 * sale was for until the next run.
 */
final readonly class SyncStore
{
    public function __construct(
        private CommerceSyncAdapterRegistry $adapters,
        private CommerceStore $store,
        private ProviderConnectionRepository $connections,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $workspaceId,
        string $storeId,
        Provider $provider,
        string $storeExternalId,
        ?DateTimeImmutable $since = null,
    ): StoreSyncResult {
        if (! $this->adapters->supports($provider)) {
            // Nothing to read. Honest, and distinguishable from a failure so the interface can say so.
            return new StoreSyncResult(SyncStatus::Skipped);
        }

        $adapter = $this->adapters->for($provider);
        $connection = $this->connections->findForProvider(new WorkspaceId($workspaceId), $provider);

        if ($connection === null) {
            throw ProviderNotConfigured::for($provider, $provider->requiredCredentialKeys());
        }

        $credentials = $connection->credentials();
        $isSandbox = $credentials->isSandbox($provider->requiredCredentialKeys());
        $now = $this->clock->now();
        $from = $since ?? $now->modify('-'.max(1, $adapter->restatementWindowDays() - 1).' days');

        $customers = 0;
        $products = 0;
        $orders = 0;
        $calls = 0;
        $failure = null;

        try {
            /*
             * Customers first, then products, then orders. An order line names a product and an order
             * names a customer, and reading orders first would store nulls for both — which the next
             * run would fix, leaving a window in which a client's product report was missing its
             * best-selling item for no visible reason.
             */
            [$customers, $calls] = $this->syncCustomers($adapter, $credentials, $workspaceId, $storeId, $storeExternalId, $calls);
            [$products, $calls] = $this->syncProducts($adapter, $credentials, $workspaceId, $storeId, $storeExternalId, $calls);
            [$orders, $calls] = $this->syncOrders(
                $adapter,
                $credentials,
                $workspaceId,
                $storeId,
                $storeExternalId,
                $from,
                $now,
                $this->revenueSourceFor($provider),
                $isSandbox ? MetricSource::Sandbox : MetricSource::Synced,
                $calls,
            );

            // Derived from the orders now stored, so the figure reconciles with what a reader can see.
            $this->store->recalculateCustomerTotals($workspaceId, $storeId);
        } catch (ProviderNotConfigured|ProviderRequestFailed|ProviderUnavailable $exception) {
            /*
             * Caught narrowly, and only the three the provider layer raises. A programming error has no
             * business being recorded as a sync outcome, and swallowing one is how a store page comes
             * to read "partial" for a year.
             */
            $failure = $exception->getMessage();
        }

        /*
         * Written even on a failure. "We tried and got this far" is the fact a store page needs, and
         * leaving the timestamp alone would make a store that is failing look like one nobody asked
         * about. The orders watermark moves only when orders actually landed.
         */
        $this->store->markSynced(
            $workspaceId,
            $storeId,
            $now,
            $orders > 0 ? $now : null,
        );

        return new StoreSyncResult(
            status: $failure === null
                ? SyncStatus::Succeeded
                // Partial when something landed: those orders are real and are kept.
                : (($orders + $products + $customers) > 0 ? SyncStatus::Partial : SyncStatus::Failed),
            ordersWritten: $orders,
            productsWritten: $products,
            customersWritten: $customers,
            apiCalls: $calls,
            source: $isSandbox ? MetricSource::Sandbox : MetricSource::Synced,
            failureMessage: $failure,
        );
    }

    /**
     * Which authority this provider's orders speak with.
     *
     * A store's figures are invoiced revenue, and they are labelled as that provider's rather than as
     * a generic total — because the whole point of `RevenueSource` is that a Salla figure and a GA4
     * figure are never added together by accident.
     */
    private function revenueSourceFor(Provider $provider): RevenueSource
    {
        return match ($provider) {
            Provider::Salla => RevenueSource::Salla,
            Provider::Zid => RevenueSource::Zid,
            // Reached only if a store provider is added without deciding what it speaks for.
            default => RevenueSource::Manual,
        };
    }

    /** @return array{int, int} written and calls */
    private function syncCustomers(
        CommerceSyncAdapter $adapter,
        Credentials $credentials,
        string $workspaceId,
        string $storeId,
        string $storeExternalId,
        int $calls,
    ): array {
        $written = 0;
        $cursor = null;

        do {
            $page = $adapter->fetchCustomers($credentials, $storeExternalId, $cursor);
            $calls++;
            $written += $this->store->upsertCustomers($workspaceId, $storeId, $page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return [$written, $calls];
    }

    /** @return array{int, int} */
    private function syncProducts(
        CommerceSyncAdapter $adapter,
        Credentials $credentials,
        string $workspaceId,
        string $storeId,
        string $storeExternalId,
        int $calls,
    ): array {
        $written = 0;
        $cursor = null;

        do {
            $page = $adapter->fetchProducts($credentials, $storeExternalId, $cursor);
            $calls++;
            $written += $this->store->upsertProducts($workspaceId, $storeId, $page->items);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return [$written, $calls];
    }

    /** @return array{int, int} */
    private function syncOrders(
        CommerceSyncAdapter $adapter,
        Credentials $credentials,
        string $workspaceId,
        string $storeId,
        string $storeExternalId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        RevenueSource $revenueSource,
        MetricSource $source,
        int $calls,
    ): array {
        $written = 0;
        $cursor = null;

        do {
            $page = $adapter->fetchOrders($credentials, $storeExternalId, $from, $until, $cursor);
            $calls++;
            $written += $this->store->upsertOrders($workspaceId, $storeId, $page->items, $revenueSource, $source);
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return [$written, $calls];
    }
}
