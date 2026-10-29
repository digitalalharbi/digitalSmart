<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderItemRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Shared\ValueObject\ContactPoint;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes normalised commerce records, replacing rather than accumulating.
 *
 * Every write here upserts on the natural key the database already enforces. That is not an
 * optimisation: every sync re-reads a restatement window because orders keep changing after they are
 * placed, so the same order arrives repeatedly, and an insert would multiply a client's revenue by
 * the number of times the sync had run — a defect that would look exactly like growth.
 */
final readonly class EloquentCommerceStore implements CommerceStore
{
    /** Rows per statement. A busy store's month is a few thousand lines, not one statement's worth. */
    private const int CHUNK = 400;

    /** @param list<OrderRecord> $orders */
    public function upsertOrders(
        string $workspaceId,
        string $storeId,
        array $orders,
        RevenueSource $revenueSource,
        MetricSource $source,
    ): int {
        if ($orders === []) {
            return 0;
        }

        $customerIds = $this->customerIdsFor($storeId);
        $productIds = $this->productIdsFor($storeId);
        $variantIds = $this->variantIdsFor($storeId);
        $now = now();
        $written = 0;

        foreach (array_chunk($orders, self::CHUNK) as $chunk) {
            /*
             * One transaction per chunk. An order and its lines have to land together — a half-written
             * order would report a total with no items behind it, which reads as a data bug rather
             * than as an interrupted sync.
             */
            DB::transaction(function () use (
                $chunk, $workspaceId, $storeId, $revenueSource, $source, $customerIds,
                $productIds, $variantIds, $now, &$written
            ): void {
                $rows = [];

                foreach ($chunk as $order) {
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'workspace_id' => $workspaceId,
                        'store_id' => $storeId,
                        'store_customer_id' => $order->customerExternalId === null
                            ? null
                            : ($customerIds[$order->customerExternalId] ?? null),
                        'external_id' => $order->externalId,
                        'reference' => $order->reference,
                        'payment_state' => $order->paymentState->value,
                        'fulfilment_state' => $order->fulfilmentState->value,
                        // The provider's own words, for when a mapping is disputed.
                        'raw_payment_status' => $order->rawPaymentStatus,
                        'raw_fulfilment_status' => $order->rawFulfilmentStatus,
                        'currency' => strtoupper($order->currency),
                        'subtotal_minor' => $order->subtotalMinor,
                        'discount_minor' => $order->discountMinor,
                        'shipping_minor' => $order->shippingMinor,
                        'tax_minor' => $order->taxMinor,
                        'total_minor' => $order->totalMinor,
                        'refunded_minor' => $order->refundedMinor,
                        'payment_method' => $order->paymentMethod,
                        'gateway' => $order->gateway,
                        'attribution_evidence' => $order->attribution->evidence->value,
                        'utm_source' => $order->attribution->source,
                        'utm_medium' => $order->attribution->medium,
                        'utm_campaign' => $order->attribution->campaign,
                        'utm_content' => $order->attribution->content,
                        'utm_term' => $order->attribution->term,
                        'click_identifier' => $order->attribution->clickIdentifier,
                        'coupon_code' => $order->attribution->couponCode,
                        'referrer' => $order->attribution->referrer,
                        'revenue_source' => $revenueSource->value,
                        'source' => $source->value,
                        'placed_at' => self::utc($order->placedAt),
                        'paid_at' => self::utc($order->paidAt),
                        'cancelled_at' => self::utc($order->cancelledAt),
                        'provider_updated_at' => self::utc($order->providerUpdatedAt),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('store_orders')->upsert(
                    $rows,
                    ['store_id', 'external_id'],
                    [
                        'store_customer_id', 'reference', 'payment_state', 'fulfilment_state',
                        'raw_payment_status', 'raw_fulfilment_status', 'currency', 'subtotal_minor',
                        'discount_minor', 'shipping_minor', 'tax_minor', 'total_minor',
                        'refunded_minor', 'payment_method', 'gateway', 'attribution_evidence',
                        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
                        'click_identifier', 'coupon_code', 'referrer', 'revenue_source', 'source',
                        'placed_at', 'paid_at', 'cancelled_at', 'provider_updated_at', 'updated_at',
                    ],
                );

                $written += count($rows);

                $this->writeChildren($workspaceId, $storeId, $chunk, $productIds, $variantIds, $now);
            });
        }

        return $written;
    }

    /** @param list<ProductRecord> $products */
    public function upsertProducts(string $workspaceId, string $storeId, array $products): int
    {
        if ($products === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'store_id' => $storeId,
                'external_id' => $product->externalId,
                'name' => $product->name,
                'sku' => $product->sku,
                'status' => $product->status,
                'price_minor' => $product->priceMinor,
                'currency' => $product->currency === null ? null : strtoupper($product->currency),
                'image_url' => $product->imageUrl,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('store_products')->upsert(
                $chunk,
                ['store_id', 'external_id'],
                ['name', 'sku', 'status', 'price_minor', 'currency', 'image_url', 'updated_at'],
            );
        }

        $this->writeVariants($workspaceId, $storeId, $products, $now);

        return count($rows);
    }

    /** @param list<CustomerRecord> $customers */
    public function upsertCustomers(string $workspaceId, string $storeId, array $customers): int
    {
        if ($customers === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($customers as $customer) {
            /*
             * Normalised with the same value object the leads use, so an enquiry and an order from one person
             * are recognised as one person. Two modules normalising a phone number differently would be two
             * modules that disagree about how many customers a client has.
             */
            $contact = ContactPoint::of($customer->email, $customer->phone);

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'store_id' => $storeId,
                'external_id' => $customer->externalId,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'email_key' => $contact->email,
                'phone_key' => $contact->phone,
                'city' => $customer->city,
                'country' => $customer->country,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            /*
             * The lifetime totals are deliberately absent from the update list. They are derived from
             * the orders actually stored by `recalculateCustomerTotals`, and letting a customer sync
             * overwrite them with the provider's own figure would replace a number a reader can
             * reconcile with one they cannot.
             */
            DB::table('store_customers')->upsert(
                $chunk,
                ['store_id', 'external_id'],
                ['name', 'email', 'phone', 'email_key', 'phone_key', 'city', 'country', 'updated_at'],
            );
        }

        return count($rows);
    }

    public function recalculateCustomerTotals(string $workspaceId, string $storeId): void
    {
        /*
         * Computed from the orders in front of the reader, counting only states that recognise
         * revenue and subtracting what came back. A provider's own lifetime total includes orders
         * this workspace may never have read and counts refunds on its own terms, so the two will
         * differ — and the one that reconciles is worth more than the one that is larger.
         */
        DB::statement(
            <<<'SQL'
            UPDATE store_customers AS c
            SET orders_count = COALESCE(o.orders_count, 0),
                total_spent_minor = COALESCE(o.total_spent, 0),
                first_order_at = o.first_order_at,
                last_order_at = o.last_order_at,
                updated_at = NOW()
            FROM (
                SELECT store_customer_id,
                       COUNT(*) AS orders_count,
                       GREATEST(SUM(total_minor - refunded_minor), 0) AS total_spent,
                       MIN(placed_at) AS first_order_at,
                       MAX(placed_at) AS last_order_at
                  FROM store_orders
                 WHERE store_id = ?
                   AND store_customer_id IS NOT NULL
                   AND payment_state IN ('paid', 'partially_refunded')
              GROUP BY store_customer_id
            ) AS o
            WHERE c.id = o.store_customer_id
              AND c.store_id = ?
              AND c.workspace_id = ?
            SQL,
            [$storeId, $storeId, $workspaceId],
        );
    }

    public function markSynced(
        string $workspaceId,
        string $storeId,
        DateTimeImmutable $syncedAt,
        ?DateTimeImmutable $ordersSyncedThrough = null,
    ): void {
        $attributes = ['last_synced_at' => $syncedAt];

        if ($ordersSyncedThrough !== null) {
            /*
             * Forward only, settled in SQL. A backfill of an older window reads genuine orders with
             * older timestamps, and letting those win would make the next sync re-read months it had
             * already covered — or, worse, skip the days between.
             */
            $attributes['orders_synced_through'] = DB::raw(sprintf(
                "greatest(coalesce(orders_synced_through, '-infinity'::timestamp), '%s'::timestamp)",
                $ordersSyncedThrough->format('Y-m-d H:i:s'),
            ));
        }

        Store::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($storeId)
            ->update($attributes);
    }

    /**
     * Items, refunds and shipments for a chunk of orders.
     *
     * @param  list<OrderRecord>  $orders
     * @param  array<string, string>  $productIds
     * @param  array<string, string>  $variantIds
     */
    private function writeChildren(
        string $workspaceId,
        string $storeId,
        array $orders,
        array $productIds,
        array $variantIds,
        mixed $now,
    ): void {
        $orderIds = $this->orderIdsFor($storeId, array_map(
            static fn (OrderRecord $order): string => $order->externalId,
            $orders,
        ));

        $items = [];
        $refunds = [];
        $shipments = [];
        $touched = [];

        foreach ($orders as $order) {
            $orderId = $orderIds[$order->externalId] ?? null;

            if ($orderId === null) {
                continue;
            }

            $touched[] = $orderId;

            foreach ($order->items as $item) {
                $items[] = $this->itemRow($workspaceId, $orderId, $item, $productIds, $variantIds, $now);
            }

            foreach ($order->refunds as $refund) {
                $refunds[] = [
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $workspaceId,
                    'store_order_id' => $orderId,
                    'external_id' => $refund->externalId,
                    'amount_minor' => $refund->amountMinor,
                    'currency' => strtoupper($refund->currency),
                    'reason' => $refund->reason,
                    'refunded_at' => self::utc($refund->refundedAt),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($order->shipments as $shipment) {
                $shipments[] = [
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $workspaceId,
                    'store_order_id' => $orderId,
                    'external_id' => $shipment->externalId,
                    'carrier' => $shipment->carrier,
                    'tracking_number' => $shipment->trackingNumber,
                    'status' => $shipment->status,
                    'shipped_at' => self::utc($shipment->shippedAt),
                    'delivered_at' => self::utc($shipment->deliveredAt),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($touched === []) {
            return;
        }

        /*
         * Replaced, not merged. The lines on an order are the whole truth about what was sold, so a
         * line removed in an amended order has to stop counting — the same reasoning as a dashboard's
         * widgets or a conversion's mappings. Refunds and shipments go the same way: an order's
         * refunds are a set, and a refund the provider reversed must not linger.
         */
        DB::table('store_order_items')->whereIn('store_order_id', $touched)->delete();
        DB::table('store_refunds')->whereIn('store_order_id', $touched)->delete();
        DB::table('store_shipments')->whereIn('store_order_id', $touched)->delete();

        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            DB::table('store_order_items')->insert($chunk);
        }

        foreach (array_chunk($refunds, self::CHUNK) as $chunk) {
            DB::table('store_refunds')->insert($chunk);
        }

        foreach (array_chunk($shipments, self::CHUNK) as $chunk) {
            DB::table('store_shipments')->insert($chunk);
        }
    }

    /**
     * @param  array<string, string>  $productIds
     * @param  array<string, string>  $variantIds
     * @return array<string, mixed>
     */
    private function itemRow(
        string $workspaceId,
        string $orderId,
        OrderItemRecord $item,
        array $productIds,
        array $variantIds,
        mixed $now,
    ): array {
        return [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'store_order_id' => $orderId,
            /*
             * Null when the catalogue no longer holds it. The line is still a real line of a real
             * sale, and dropping it would change last quarter's revenue because somebody deleted a
             * product this morning.
             */
            'store_product_id' => $item->productExternalId === null
                ? null
                : ($productIds[$item->productExternalId] ?? null),
            'store_product_variant_id' => $item->variantExternalId === null
                ? null
                : ($variantIds[$item->variantExternalId] ?? null),
            'external_id' => $item->externalId,
            'name' => $item->name,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unitPriceMinor,
            'discount_minor' => $item->discountMinor,
            'total_minor' => $item->totalMinor,
            'currency' => strtoupper($item->currency),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param list<ProductRecord> $products */
    private function writeVariants(string $workspaceId, string $storeId, array $products, mixed $now): void
    {
        $productIds = $this->productIdsFor($storeId);
        $rows = [];

        foreach ($products as $product) {
            $productId = $productIds[$product->externalId] ?? null;

            if ($productId === null) {
                continue;
            }

            foreach ($product->variants as $variant) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'workspace_id' => $workspaceId,
                    'store_product_id' => $productId,
                    'external_id' => $variant->externalId,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price_minor' => $variant->priceMinor,
                    'currency' => $variant->currency === null ? null : strtoupper($variant->currency),
                    'stock' => $variant->stock,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            /*
             * Upserted rather than replaced, unlike order lines: a variant is referenced by order
             * items, and deleting it to rewrite it would null those references — losing which variant
             * of a product a past sale was for.
             */
            DB::table('store_product_variants')->upsert(
                $chunk,
                ['store_product_id', 'external_id'],
                ['name', 'sku', 'price_minor', 'currency', 'stock', 'updated_at'],
            );
        }
    }

    /** @return array<string, string> external id to row id */
    private function customerIdsFor(string $storeId): array
    {
        return $this->lookup('store_customers', 'store_id', $storeId);
    }

    /** @return array<string, string> */
    private function productIdsFor(string $storeId): array
    {
        return $this->lookup('store_products', 'store_id', $storeId);
    }

    /** @return array<string, string> */
    private function variantIdsFor(string $storeId): array
    {
        $rows = DB::table('store_product_variants as v')
            ->join('store_products as p', 'p.id', '=', 'v.store_product_id')
            ->where('p.store_id', $storeId)
            ->get(['v.id', 'v.external_id']);

        $map = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $map[(string) ($values['external_id'] ?? '')] = (string) ($values['id'] ?? '');
        }

        unset($map['']);

        return $map;
    }

    /**
     * @param  list<string>  $externalIds
     * @return array<string, string>
     */
    private function orderIdsFor(string $storeId, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $rows = DB::table('store_orders')
            ->where('store_id', $storeId)
            ->whereIn('external_id', $externalIds)
            ->get(['id', 'external_id']);

        $map = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $map[(string) ($values['external_id'] ?? '')] = (string) ($values['id'] ?? '');
        }

        unset($map['']);

        return $map;
    }

    /** @return array<string, string> */
    private function lookup(string $table, string $column, string $value): array
    {
        $rows = DB::table($table)->where($column, $value)->get(['id', 'external_id']);
        $map = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $map[(string) ($values['external_id'] ?? '')] = (string) ($values['id'] ?? '');
        }

        unset($map['']);

        return $map;
    }

    /**
     * A provider's instant, as the UTC wall-clock string the `timestamp` columns hold.
     *
     * The mappers keep the zone a payload carried — Salla's `Asia/Riyadh` — which is right for an instant. But the query builder
     * formats a DateTimeInterface in *its own* zone without converting it, so binding one straight in stored 01:30 Riyadh time as
     * `01:30` in a column every query reads as UTC. Converting here, once, is what lets a day boundary later be cut in a store's
     * own zone and mean the same thing for every provider.
     */
    private static function utc(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
