<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderItemRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\ProductRecord;
use App\Application\Commerce\DTO\RefundRecord;
use App\Application\Commerce\DTO\ShipmentRecord;
use App\Application\Commerce\DTO\VariantRecord;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Commerce persistence
|--------------------------------------------------------------------------
|
| The behaviour that stops a client's revenue being wrong. Every sync re-reads a window because
| orders keep changing after they are placed, so the same order arrives repeatedly — and an insert
| rather than an upsert would multiply a client's revenue by the number of runs, which would look
| exactly like growth.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function commerceScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'store-9001',
        'name' => 'Noon Retail storefront',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => $store];
}

function commerce(): CommerceStore
{
    return app(CommerceStore::class);
}

/** @param list<OrderItemRecord> $items */
function order(
    string $externalId = 'order-1',
    PaymentState $payment = PaymentState::Paid,
    int $total = 45_000,
    int $refunded = 0,
    array $items = [],
    ?OrderAttribution $attribution = null,
    ?string $customer = null,
    string $placedAt = '2026-10-01 10:00:00',
): OrderRecord {
    return new OrderRecord(
        externalId: $externalId,
        paymentState: $payment,
        fulfilmentState: FulfilmentState::Shipped,
        currency: 'SAR',
        totalMinor: $total,
        placedAt: new DateTimeImmutable($placedAt),
        attribution: $attribution ?? OrderAttribution::none(),
        reference: 'NR-'.$externalId,
        rawPaymentStatus: 'completed',
        rawFulfilmentStatus: 'shipped',
        subtotalMinor: $total,
        refundedMinor: $refunded,
        customerExternalId: $customer,
        items: $items,
    );
}

function item(string $name = 'Abaya', int $quantity = 1, int $unit = 45_000): OrderItemRecord
{
    return new OrderItemRecord(
        name: $name,
        quantity: $quantity,
        unitPriceMinor: $unit,
        totalMinor: $unit * $quantity,
        currency: 'SAR',
        sku: strtoupper(substr($name, 0, 3)).'-1',
    );
}

/**
 * The single row of a table, failing the test outright if nothing was written.
 *
 * `first()` returns null for an empty table, and asserting against null reports "expected 45000, got
 * nothing useful" — which reads as a value bug rather than as nothing having been written at all.
 */
function commerceRow(string $table): stdClass
{
    $row = DB::table($table)->first();

    expect($row)->not->toBeNull("no row written to {$table}");

    /** @var stdClass $row */
    return $row;
}

describe('writing orders', function (): void {
    it('stores an order with both state axes and its raw strings', function (): void {
        $setup = commerceScenario();

        $written = commerce()->upsertOrders(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [order()],
            RevenueSource::Salla,
            MetricSource::Sandbox,
        );

        $row = commerceRow('store_orders');

        expect($written)->toBe(1)
            ->and($row->payment_state)->toBe('paid')
            ->and($row->fulfilment_state)->toBe('shipped')
            /*
             * The provider's own words, kept for when a mapping is disputed — and it will be, the
             * first time a client's figures differ from their platform dashboard.
             */
            ->and($row->raw_payment_status)->toBe('completed')
            ->and($row->revenue_source)->toBe('salla')
            // Fixtures are never shown as production, whatever else is true of them.
            ->and($row->source)->toBe('sandbox');
    });

    it('replaces rather than accumulates when the same window is read again', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->upsertOrders($workspaceId, $storeId, [order(items: [item()])], RevenueSource::Salla, MetricSource::Synced);
        commerce()->upsertOrders($workspaceId, $storeId, [order(items: [item()])], RevenueSource::Salla, MetricSource::Synced);

        /*
         * The defect this guards against would look like growth: every sync re-reads a restatement
         * window, so inserting would multiply a client's revenue by the number of runs.
         */
        expect(DB::table('store_orders')->count())->toBe(1)
            ->and(DB::table('store_order_items')->count())->toBe(1)
            ->and((int) DB::table('store_orders')->sum('total_minor'))->toBe(45_000);
    });

    it('updates an order that changed after it was placed', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->upsertOrders($workspaceId, $storeId, [order()], RevenueSource::Salla, MetricSource::Synced);

        // A month-old order refunded this morning. The whole reason the sync re-reads a window.
        commerce()->upsertOrders(
            $workspaceId,
            $storeId,
            [order(payment: PaymentState::Refunded, refunded: 45_000)],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        $row = commerceRow('store_orders');

        expect($row->payment_state)->toBe('refunded')
            ->and((int) $row->refunded_minor)->toBe(45_000);
    });

    it('replaces an amended order\'s lines instead of merging them', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->upsertOrders(
            $workspaceId,
            $storeId,
            [order(items: [item('Abaya'), item('Scarf', unit: 9_000)])],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        commerce()->upsertOrders(
            $workspaceId,
            $storeId,
            [order(items: [item('Abaya')])],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        /*
         * The lines on an order are the whole truth about what was sold, so a line removed in an
         * amendment has to stop counting — the same reasoning as a dashboard's widgets.
         */
        expect(DB::table('store_order_items')->pluck('name')->all())->toBe(['Abaya']);
    });

    it('keeps refunds individually, and replaces the set on a re-read', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        $withRefund = new OrderRecord(
            externalId: 'order-1',
            paymentState: PaymentState::PartiallyRefunded,
            fulfilmentState: FulfilmentState::Delivered,
            currency: 'SAR',
            totalMinor: 45_000,
            placedAt: new DateTimeImmutable('2026-10-01 10:00:00'),
            attribution: OrderAttribution::none(),
            refundedMinor: 9_000,
            refunds: [
                new RefundRecord(
                    amountMinor: 9_000,
                    currency: 'SAR',
                    refundedAt: new DateTimeImmutable('2026-10-04 12:00:00'),
                    externalId: 'refund-1',
                    reason: 'Wrong size',
                ),
            ],
            shipments: [
                new ShipmentRecord(
                    externalId: 'ship-1',
                    carrier: 'SMSA',
                    trackingNumber: 'SM123456',
                    status: 'delivered',
                    shippedAt: new DateTimeImmutable('2026-10-02 08:00:00'),
                    deliveredAt: new DateTimeImmutable('2026-10-03 14:00:00'),
                ),
            ],
        );

        commerce()->upsertOrders($workspaceId, $storeId, [$withRefund], RevenueSource::Salla, MetricSource::Synced);
        commerce()->upsertOrders($workspaceId, $storeId, [$withRefund], RevenueSource::Salla, MetricSource::Synced);

        /*
         * Refunds are kept as records rather than only as an order total, because "how much came back
         * and when" is the figure that moves a client's ROAS a month after the sale.
         */
        expect(DB::table('store_refunds')->count())->toBe(1)
            ->and(DB::table('store_shipments')->count())->toBe(1)
            ->and(commerceRow('store_refunds')->reason)->toBe('Wrong size');
    });

    it('refuses an order claiming evidence it does not carry', function (): void {
        $setup = commerceScenario();

        /*
         * The constraint the orders table exists to carry. An order claiming campaign parameters with
         * no parameters on it is the fiction that would let a client be told which campaign earned
         * their money on the strength of nothing — and it is the one defect here that no report could
         * reveal afterwards. Enforced in the database so a second write path cannot bypass it.
         */
        expect(fn (): int => DB::table('store_orders')->insert([
            'id' => (string) Illuminate\Support\Str::uuid7(),
            'workspace_id' => $setup['scenario']->workspace->getKey(),
            'store_id' => $setup['store']->getKey(),
            'external_id' => 'fabricated',
            'payment_state' => 'paid',
            'fulfilment_state' => 'shipped',
            'currency' => 'SAR',
            'total_minor' => 10_000,
            'attribution_evidence' => 'utm_parameters',
            'revenue_source' => 'salla',
            'placed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]) ? 1 : 0)->toThrow(Illuminate\Database\QueryException::class);
    });

    it('stores the attribution an order actually carried', function (): void {
        $setup = commerceScenario();

        commerce()->upsertOrders(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [order(attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid-launch',
                clickIdentifier: 'fbclid-123',
            ))],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        $row = commerceRow('store_orders');

        expect($row->attribution_evidence)->toBe('click_identifier')
            ->and($row->utm_campaign)->toBe('eid-launch')
            ->and($row->click_identifier)->toBe('fbclid-123');
    });
});

describe('customers', function (): void {
    it('derives lifetime totals from the orders actually stored', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->upsertCustomers($workspaceId, $storeId, [
            new CustomerRecord(externalId: 'cust-1', name: 'Layla', email: 'layla@example.test'),
        ]);

        commerce()->upsertOrders($workspaceId, $storeId, [
            order('order-1', total: 45_000, customer: 'cust-1', placedAt: '2026-09-01 10:00:00'),
            order('order-2', total: 30_000, refunded: 5_000, payment: PaymentState::PartiallyRefunded, customer: 'cust-1', placedAt: '2026-10-01 10:00:00'),
            // Pending: a real order, and not yet a sale. Must not reach the lifetime total.
            order('order-3', total: 99_000, payment: PaymentState::Pending, customer: 'cust-1', placedAt: '2026-10-05 10:00:00'),
        ], RevenueSource::Salla, MetricSource::Synced);

        commerce()->recalculateCustomerTotals($workspaceId, $storeId);

        $customer = commerceRow('store_customers');

        /*
         * 45,000 plus 30,000 less the 5,000 that came back. The pending order is excluded, because
         * cash on delivery that never arrives is not a sale — and a derived figure a reader can
         * reconcile against the orders in front of them is worth more than the provider's own, which
         * includes orders this workspace may never have read.
         */
        expect((int) $customer->total_spent_minor)->toBe(70_000)
            ->and((int) $customer->orders_count)->toBe(2)
            ->and($customer->first_order_at)->toContain('2026-09-01');
    });

    it('does not let a customer re-sync overwrite the derived totals', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->upsertCustomers($workspaceId, $storeId, [new CustomerRecord(externalId: 'cust-1', name: 'Layla')]);
        commerce()->upsertOrders($workspaceId, $storeId, [order(customer: 'cust-1')], RevenueSource::Salla, MetricSource::Synced);
        commerce()->recalculateCustomerTotals($workspaceId, $storeId);

        // A later customer sync, which must not reset what the orders say.
        commerce()->upsertCustomers($workspaceId, $storeId, [
            new CustomerRecord(externalId: 'cust-1', name: 'Layla Al-Harbi'),
        ]);

        $customer = commerceRow('store_customers');

        expect($customer->name)->toBe('Layla Al-Harbi')
            ->and((int) $customer->total_spent_minor)->toBe(45_000);
    });
});

describe('products', function (): void {
    it('stores products with their variants and keeps variant identity across syncs', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        $product = new ProductRecord(
            externalId: 'prod-1',
            name: 'Abaya',
            sku: 'ABA-1',
            priceMinor: 45_000,
            currency: 'SAR',
            variants: [
                new VariantRecord(externalId: 'var-s', name: 'Small', priceMinor: 45_000, currency: 'SAR', stock: 4),
                new VariantRecord(externalId: 'var-m', name: 'Medium', priceMinor: 45_000, currency: 'SAR'),
            ],
        );

        commerce()->upsertProducts($workspaceId, $storeId, [$product]);
        $firstIds = DB::table('store_product_variants')->orderBy('external_id')->pluck('id')->all();

        commerce()->upsertProducts($workspaceId, $storeId, [$product]);
        $secondIds = DB::table('store_product_variants')->orderBy('external_id')->pluck('id')->all();

        /*
         * Variants are upserted rather than replaced, unlike order lines: an order item references a
         * variant, and deleting it to rewrite it would null that reference — losing which variant of
         * a product a past sale was for.
         */
        expect(DB::table('store_products')->count())->toBe(1)
            ->and($secondIds)->toBe($firstIds);
    });

    it('distinguishes untracked stock from none', function (): void {
        $setup = commerceScenario();

        commerce()->upsertProducts(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [new ProductRecord(
                externalId: 'prod-1',
                name: 'Abaya',
                variants: [
                    new VariantRecord(externalId: 'var-s', stock: 0),
                    new VariantRecord(externalId: 'var-m'),
                ],
            )],
        );

        $stocks = DB::table('store_product_variants')->orderBy('external_id')->pluck('stock')->all();

        // Ordered by external id, so `var-m` (untracked) comes before `var-s` (zero). Out of stock
        // and not tracked are different facts, and only one of them is a reason to stop advertising
        // a product.
        expect($stocks)->toBe([null, 0]);
    });
});

describe('sync bookkeeping', function (): void {
    it('moves the orders watermark forward only', function (): void {
        $setup = commerceScenario();
        $workspaceId = (string) $setup['scenario']->workspace->getKey();
        $storeId = (string) $setup['store']->getKey();

        commerce()->markSynced($workspaceId, $storeId, new DateTimeImmutable('2026-10-06 09:00:00'), new DateTimeImmutable('2026-10-05 00:00:00'));
        // A backfill of an older range reads genuine orders with older timestamps.
        commerce()->markSynced($workspaceId, $storeId, new DateTimeImmutable('2026-10-06 10:00:00'), new DateTimeImmutable('2026-08-01 00:00:00'));

        $store = Store::query()->findOrFail($storeId);

        /*
         * Letting the older value win would make the next sync either re-read months it had already
         * covered or skip the days between — and the second is silent.
         */
        expect($store->getAttribute('orders_synced_through')?->format('Y-m-d'))->toBe('2026-10-05')
            ->and($store->getAttribute('last_synced_at')?->format('H:i'))->toBe('10:00');
    });
});

describe('webhook arrivals', function (): void {
    it('records an arrival once, and reports a redelivery as already seen', function (): void {
        $setup = commerceScenario();
        $journal = app(WebhookEventJournal::class);
        $workspaceId = (string) $setup['scenario']->workspace->getKey();

        $first = $journal->record(
            $workspaceId,
            Provider::Salla,
            'order.payment.updated',
            'evt-1001',
            ['order' => ['id' => 9001]],
            signatureVerified: true,
            storeId: (string) $setup['store']->getKey(),
        );

        $second = $journal->record(
            $workspaceId,
            Provider::Salla,
            'order.payment.updated',
            'evt-1001',
            ['order' => ['id' => 9001]],
            signatureVerified: true,
        );

        /*
         * Providers redeliver on any non-2xx, and a redelivered order-paid event processed twice
         * doubles a day's revenue. Null is the signal to answer 200 and do nothing — enforced by the
         * unique index rather than by a check that could lose a race.
         */
        expect($first)->not->toBeNull()
            ->and($second)->toBeNull()
            ->and(DB::table('store_webhook_events')->count())->toBe(1);
    });

    it('records an unverified payload as rejected rather than discarding it', function (): void {
        $setup = commerceScenario();

        app(WebhookEventJournal::class)->record(
            (string) $setup['scenario']->workspace->getKey(),
            Provider::Salla,
            'order.created',
            'evt-2002',
            ['spoofed' => true],
            signatureVerified: false,
        );

        $row = commerceRow('store_webhook_events');

        /*
         * Kept for diagnosis and never processed. The arrival is a fact worth having — it is how
         * somebody finds out an endpoint is being probed — and storing which it was is the only way
         * to be sure afterwards that it was not acted on.
         */
        expect($row->status)->toBe('rejected')
            ->and((bool) $row->signature_verified)->toBeFalse();
    });

    it('processes two genuinely different events from one provider', function (): void {
        $setup = commerceScenario();
        $journal = app(WebhookEventJournal::class);
        $workspaceId = (string) $setup['scenario']->workspace->getKey();

        $first = $journal->record($workspaceId, Provider::Salla, 'order.created', 'evt-1', [], true);
        $second = $journal->record($workspaceId, Provider::Salla, 'order.created', 'evt-2', [], true);

        expect($first)->not->toBeNull()->and($second)->not->toBeNull();
    });

    it('tracks an event through to processed', function (): void {
        $setup = commerceScenario();
        $journal = app(WebhookEventJournal::class);

        $id = $journal->record(
            (string) $setup['scenario']->workspace->getKey(),
            Provider::Salla,
            'order.created',
            'evt-3',
            [],
            true,
        );

        expect($id)->not->toBeNull();

        $journal->markQueued((string) $id);
        expect(commerceRow('store_webhook_events')->status)->toBe('queued');

        $journal->markProcessed((string) $id);
        $row = commerceRow('store_webhook_events');

        // "Why is yesterday missing?" has to be answerable from the product, not from a log file.
        expect($row->status)->toBe('processed')
            ->and($row->processed_at)->not->toBeNull();
    });

    it('calls an unhandled topic ignored, not failed', function (): void {
        $setup = commerceScenario();
        $journal = app(WebhookEventJournal::class);

        $id = (string) $journal->record(
            (string) $setup['scenario']->workspace->getKey(),
            Provider::Salla,
            'customer.login',
            'evt-4',
            [],
            true,
        );

        $journal->markIgnored($id, 'Nothing to do for this topic.');

        // A topic this product does not act on is a normal thing to receive, and a wall of "failed"
        // rows would make the real failures unfindable.
        expect(commerceRow('store_webhook_events')->status)->toBe('ignored');
    });
});
