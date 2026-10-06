<?php

declare(strict_types=1);

use App\Application\Commerce\Command\SyncStore;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Zid
|--------------------------------------------------------------------------
|
| The second store platform. Two things are worth proving here: that the normalised domain fits a
| provider it was not designed around, and that Zid's weaker webhook authentication is implemented as
| what it is rather than dressed up as a signature.
|
*/

const ZID_BASIC_SECRET = 'digitalsmart:sandbox-webhook-password';

const ZID_STORE = '7741002';

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function zidScenario(bool $sandbox = true, string $secret = ZID_BASIC_SECRET): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::Zid,
        name: 'Zid',
        credentials: new Credentials([
            'client_id' => $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-id',
            'client_secret' => $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-secret',
            /*
             * For Zid this credential holds `username:password` as configured on the webhook
             * subscription, because Zid authenticates with Basic Auth rather than by signing the body.
             */
            'webhook_secret' => $secret,
        ]),
    );

    app(ProviderConnectionRepository::class)->save($connection);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => Provider::Zid->value,
        'external_id' => ZID_STORE,
        'name' => 'SANDBOX — Rawdah Home',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => $store];
}

/** @param array{scenario: WorkspaceScenario, client: Client, store: Store} $setup */
function runZidSync(array $setup): App\Application\Commerce\DTO\StoreSyncResult
{
    return app(SyncStore::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['store']->getKey(),
        Provider::Zid,
        ZID_STORE,
    );
}

/** One stored order by its Zid id. */
function zidOrderRow(string $externalId): stdClass
{
    $row = DB::table('store_orders')->where('external_id', $externalId)->first();

    expect($row)->not->toBeNull("no order stored for {$externalId}");

    /** @var stdClass $row */
    return $row;
}

/** One stored customer by its Zid id. */
function zidCustomerRow(string $externalId): stdClass
{
    $row = DB::table('store_customers')->where('external_id', $externalId)->first();

    expect($row)->not->toBeNull("no customer stored for {$externalId}");

    /** @var stdClass $row */
    return $row;
}

describe('reading a Zid store', function (): void {
    it('reads customers, products and orders', function (): void {
        $setup = zidScenario();

        $result = runZidSync($setup);

        expect($result->status)->toBe(SyncStatus::Succeeded)
            ->and($result->customersWritten)->toBe(4)
            ->and($result->productsWritten)->toBe(4)
            ->and($result->ordersWritten)->toBe(5)
            ->and($result->source->value)->toBe('sandbox');
    });

    it('labels Zid revenue as Zid\'s, never as a generic total', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        /*
         * The whole point of `RevenueSource`. A Zid figure and a GA4 figure must never be added together
         * by accident, and the only way to be sure of that is for each row to say who it came from.
         */
        expect(DB::table('store_orders')->distinct()->pluck('revenue_source')->all())->toBe(['zid']);
    });

    it('fits the normalised domain without bending it', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        $delivered = zidOrderRow('8810001');
        $cod = zidOrderRow('8810002');

        /*
         * Zid publishes payment and fulfilment as two separate fields — the shape Phase 11 chose before
         * either provider was written. Salla needed a documented boolean to recover the payment axis
         * from one status; Zid simply states it, and the same two columns hold both.
         */
        expect($delivered->payment_state)->toBe('paid')
            ->and($delivered->fulfilment_state)->toBe('delivered')
            ->and($cod->payment_state)->toBe('pending')
            ->and($cod->fulfilment_state)->toBe('shipped');
    });

    it('keeps re-reading an order whose status the merchant invented', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        $custom = zidOrderRow('8810003');

        /*
         * `manual_verified` is a status this merchant defined. The product does not guess what it means:
         * it records the code, says the order is in progress, and leaves it unsettled so the next sync
         * looks again. A state we cannot interpret should keep us looking.
         */
        expect($custom->raw_fulfilment_status)->toBe('manual_verified')
            ->and($custom->fulfilment_state)->toBe('processing')
            // Zid stated the payment outright, so that axis is unaffected by the unknown code.
            ->and($custom->payment_state)->toBe('paid');
    });

    it('reads a reversal as a refund and a return', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        $reversed = zidOrderRow('8810004');

        // Zid records the money going back as a credit note; the goods coming back is the other axis.
        expect($reversed->payment_state)->toBe('refunded')
            ->and($reversed->fulfilment_state)->toBe('returned')
            ->and((int) $reversed->refunded_minor)->toBe(34_000)
            ->and(DB::table('store_refunds')->count())->toBe(1);
    });

    it('stores a marketplace order without a customer to link it to', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        $marketplace = zidOrderRow('8810005');

        /*
         * A real sale with real revenue and a masked buyer. The order is kept; the customer is not
         * invented. A fabricated e-mail in a client's customer list would be indistinguishable from a
         * real one afterwards, and would poison every later attempt to match an order to a lead.
         */
        expect((int) $marketplace->total_minor)->toBe(9_500)
            ->and($marketplace->payment_state)->toBe('paid')
            ->and($marketplace->store_customer_id)->toBeNull();
    });

    it('derives a customer\'s lifetime total from the orders that count', function (): void {
        $setup = zidScenario();

        runZidSync($setup);

        $noura = zidCustomerRow('9910001');

        /*
         * Noura has two orders: one delivered and paid at 391.00, one reversed and fully refunded at
         * 340.00. The refunded one contributes nothing.
         */
        expect((int) $noura->total_spent_minor)->toBe(39_100)
            ->and((int) $noura->orders_count)->toBe(1);
    });

    it('reaches the same figures however many times it runs', function (): void {
        $setup = zidScenario();

        runZidSync($setup);
        $total = (int) DB::table('store_orders')->sum('total_minor');
        $items = DB::table('store_order_items')->count();

        runZidSync($setup);
        runZidSync($setup);

        expect((int) DB::table('store_orders')->sum('total_minor'))->toBe($total)
            ->and(DB::table('store_order_items')->count())->toBe($items)
            ->and(DB::table('store_refunds')->count())->toBe(1);
    });

    it('fails loudly on real credentials rather than inventing orders', function (): void {
        $setup = zidScenario(sandbox: false);

        $result = runZidSync($setup);

        expect($result->status)->toBe(SyncStatus::Failed)
            ->and($result->ordersWritten)->toBe(0)
            ->and($result->failureMessage)->not->toBeNull();

        expect(DB::table('store_orders')->count())->toBe(0);
    });
});

describe('Zid webhooks authenticate with Basic Auth, not a signature', function (): void {
    /**
     * Post a Zid webhook the way Zid does: Basic credentials, no body signature.
     *
     * @param  array<string, mixed>  $payload
     */
    $post = static function (array $payload, ?string $authorization): Illuminate\Testing\TestResponse {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($authorization !== null) {
            $headers['HTTP_AUTHORIZATION'] = $authorization;
        }

        return test()->call(
            'POST',
            '/api/webhooks/stores/zid',
            [],
            [],
            [],
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    };

    /** @return array<string, mixed> */
    $envelope = static fn (string $event = 'order.status.update'): array => [
        'event' => $event,
        'merchant' => (int) ZID_STORE,
        'created_at' => '2026-10-06 12:00:00',
        'data' => [
            'id' => 8810001,
            'code' => 'RW-5501',
            'created_at' => '2026-10-05 13:10:00',
            'updated_at' => '2026-10-06 11:00:00',
            'order_status' => ['name' => 'Delivered', 'code' => 'delivered'],
            'payment_status' => 'paid',
            'currency_code' => 'SAR',
            'order_total' => '391.00',
            'customer' => ['id' => 9910001, 'name' => 'نورة السبيعي'],
            'products' => [[
                'id' => 77001,
                'product_id' => 5520001,
                'name' => 'مفرش قطن',
                'sku' => 'BED-001-K',
                'quantity' => 1,
                'price' => '340.00',
                'total' => '340.00',
            ]],
        ],
    ];

    it('accepts the credentials it was configured with', function () use ($post, $envelope): void {
        zidScenario();

        $post($envelope(), 'Basic '.base64_encode(ZID_BASIC_SECRET))
            ->assertOk()
            ->assertJsonPath('outcome', 'queued');
    });

    it('refuses the wrong password', function () use ($post, $envelope): void {
        Queue::fake();
        zidScenario();

        $post($envelope(), 'Basic '.base64_encode('digitalsmart:wrong'))
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('refuses a delivery with no credentials at all', function () use ($post, $envelope): void {
        Queue::fake();
        zidScenario();

        $post($envelope(), null)->assertOk()->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('refuses a scheme that is not Basic', function () use ($post, $envelope): void {
        Queue::fake();
        zidScenario();

        /*
         * A Bearer token where Basic credentials were configured is not something this product can
         * verify, and an unrecognised scheme must not be treated as a pass.
         */
        $post($envelope(), 'Bearer '.ZID_BASIC_SECRET)->assertOk()->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('refuses everything when no credential has been configured', function () use ($post, $envelope): void {
        Queue::fake();
        zidScenario(secret: '');

        $post($envelope(), 'Basic '.base64_encode('anything:atall'))
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('does not accept a Salla signature on a Zid endpoint', function () use ($envelope): void {
        Queue::fake();
        zidScenario();

        $body = json_encode($envelope(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        /*
         * Each platform's rules are kept apart on purpose. A correct HMAC of the body, which would
         * satisfy Salla, means nothing here — Zid never sends one, so accepting it would be accepting a
         * mechanism Zid does not use.
         */
        test()->call('POST', '/api/webhooks/stores/zid', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SIGNATURE' => hash_hmac('sha256', $body, ZID_BASIC_SECRET),
            'HTTP_X_SALLA_SECURITY_STRATEGY' => 'Signature',
        ], $body)->assertOk()->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('writes the order the webhook described, labelled live', function () use ($post, $envelope): void {
        zidScenario();

        $post($envelope(), 'Basic '.base64_encode(ZID_BASIC_SECRET))->assertOk();

        $order = zidOrderRow('8810001');

        expect($order->payment_state)->toBe('paid')
            ->and($order->fulfilment_state)->toBe('delivered')
            ->and((int) $order->total_minor)->toBe(39_100)
            ->and($order->revenue_source)->toBe('zid')
            ->and($order->source)->toBe('live');
    });

    it('is harmless when a delivery is replayed', function () use ($post, $envelope): void {
        zidScenario();

        $authorization = 'Basic '.base64_encode(ZID_BASIC_SECRET);

        $post($envelope(), $authorization)->assertOk();
        $post($envelope(), $authorization)->assertOk();

        /*
         * Basic Auth carries no timestamp or nonce, so a captured delivery can be replayed indefinitely.
         * What makes that harmless is the same thing that makes a missing event id harmless: the work is
         * an upsert keyed on the order's own id, so a replay rewrites the same row.
         */
        expect(DB::table('store_orders')->count())->toBe(1)
            ->and(DB::table('store_order_items')->count())->toBe(1)
            ->and((int) DB::table('store_orders')->sum('total_minor'))->toBe(39_100);
    });

    it('agrees with what the reconciliation sync writes', function () use ($post, $envelope): void {
        $setup = zidScenario();

        $post($envelope(), 'Basic '.base64_encode(ZID_BASIC_SECRET))->assertOk();
        $fromWebhook = zidOrderRow('8810001');

        runZidSync($setup);
        $afterSync = zidOrderRow('8810001');

        /*
         * One mapper for both paths, so the two agree. A second mapper is how a client's revenue comes to
         * change whenever a nightly sync overwrites what a webhook wrote an hour earlier.
         */
        expect($afterSync->payment_state)->toBe($fromWebhook->payment_state)
            ->and($afterSync->fulfilment_state)->toBe($fromWebhook->fulfilment_state)
            ->and((int) $afterSync->total_minor)->toBe((int) $fromWebhook->total_minor);
    });
});
