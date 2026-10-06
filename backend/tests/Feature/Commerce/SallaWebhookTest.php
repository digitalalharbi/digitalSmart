<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use App\Interface\Queue\ProcessStoreWebhookJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Salla webhooks
|--------------------------------------------------------------------------
|
| A public endpoint that writes a client's revenue, so most of this file is about what it refuses.
| The signature is the only thing standing between an HTTP request and a figure on a client's report.
|
*/

const SALLA_SECRET = 'sandbox-webhook-secret';

const SALLA_MERCHANT = '1183363243';

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function sallaWebhookScenario(string $secret = SALLA_SECRET, string $merchant = SALLA_MERCHANT): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::Salla,
        name: 'Salla',
        credentials: new Credentials([
            'client_id' => Credentials::SANDBOX_TOKEN,
            'client_secret' => Credentials::SANDBOX_TOKEN,
            // The per-app secret Salla signs with. Not one of the required keys: without it the
            // verifier refuses everything, which is the correct direction for a missing secret.
            'webhook_secret' => $secret,
        ]),
    );

    app(ProviderConnectionRepository::class)->save($connection);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => Provider::Salla->value,
        'external_id' => $merchant,
        'name' => 'SANDBOX — Noon Retail',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => $store];
}

/**
 * A Salla webhook envelope.
 *
 * @param  array<string, mixed>  $order
 * @return array<string, mixed>
 */
function sallaEnvelope(string $event = 'order.payment.updated', array $order = [], string $merchant = SALLA_MERCHANT): array
{
    return [
        'event' => $event,
        'merchant' => (int) $merchant,
        'created_at' => '2026-10-06 12:00:00',
        'data' => [
            'id' => 760011001,
            'reference_id' => 41201,
            'date' => ['date' => '2026-10-05 14:22:11.000000', 'timezone_type' => 3, 'timezone' => 'Asia/Riyadh'],
            'status' => ['id' => 1, 'name' => 'Completed', 'slug' => 'completed'],
            'payment_method' => 'mada',
            'is_pending_payment' => false,
            'total' => ['amount' => 925.5, 'currency' => 'SAR'],
            'customer' => ['id' => 1842003311, 'full_name' => 'Layla Al-Harbi', 'email' => 'layla@example.test'],
            'items' => [[
                'id' => 990011,
                'name' => 'عباية كلاسيك',
                'sku' => 'ABA-CL-001-S',
                'quantity' => 2,
                'product' => ['id' => 2290114501],
                'amounts' => [
                    'price_without_tax' => ['amount' => 435, 'currency' => 'SAR'],
                    'total' => ['amount' => 870, 'currency' => 'SAR'],
                ],
            ]],
            ...$order,
        ],
    ];
}

/**
 * Post a webhook the way Salla does: raw JSON, signed over exactly those bytes.
 *
 * @param  array<string, mixed>  $envelope
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\JsonResponse>
 */
function postSallaWebhook(
    array $envelope,
    ?string $secret = SALLA_SECRET,
    string $strategy = 'Signature',
    ?string $forcedSignature = null,
): Illuminate\Testing\TestResponse {
    $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signature = $forcedSignature ?? ($secret === null ? '' : hash_hmac('sha256', $body, $secret));

    return test()->call(
        'POST',
        '/api/webhooks/stores/salla',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SALLA_SECURITY_STRATEGY' => $strategy,
            'HTTP_X_SALLA_SIGNATURE' => $signature,
        ],
        $body,
    );
}

/** One row of a table, failing the test if nothing was written. */
function webhookRow(string $table): stdClass
{
    $row = DB::table($table)->first();

    expect($row)->not->toBeNull("no row written to {$table}");

    /** @var stdClass $row */
    return $row;
}

describe('believing a delivery', function (): void {
    it('accepts a correctly signed webhook and queues the work', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope())
            ->assertOk()
            ->assertJsonPath('outcome', 'queued');

        /*
         * Queued, never done inline. The provider is waiting on this response, and reading an order
         * inside the request turns one slow call into a timeout, a timeout into a redelivery, and a
         * redelivery into a queue of duplicates.
         */
        Queue::assertPushed(ProcessStoreWebhookJob::class);

        $event = webhookRow('store_webhook_events');

        expect($event->status)->toBe('queued')
            ->and((bool) $event->signature_verified)->toBeTrue();
    });

    it('refuses a forged signature, records it, and queues nothing', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope(), secret: 'the-wrong-secret')
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        /*
         * The most important test in this file. A public endpoint that writes a client's revenue has
         * exactly one gate, and this is it. Answered 200 rather than 401 on purpose: a non-2xx makes
         * Salla redeliver, and redelivering a payload that will never verify achieves nothing but
         * filling the journal.
         */
        Queue::assertNothingPushed();

        $event = webhookRow('store_webhook_events');

        expect($event->status)->toBe('rejected')
            ->and((bool) $event->signature_verified)->toBeFalse()
            // Kept, not discarded: the arrival is how somebody finds out an endpoint is being probed.
            ->and(DB::table('store_webhook_events')->count())->toBe(1);
    });

    it('refuses a delivery with no signature at all', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope(), forcedSignature: '')
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('refuses everything when no secret has been configured', function (): void {
        Queue::fake();
        sallaWebhookScenario(secret: '');

        /*
         * A deployment that forgot the secret rejects webhooks instead of accepting forgeries. The
         * failure mode is a tracking gap somebody notices, not a silent door.
         */
        postSallaWebhook(sallaEnvelope(), secret: 'anything')
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('refuses a security strategy it does not recognise', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        /*
         * False for an unknown strategy, so a future Salla strategy causes visibly rejected webhooks
         * rather than invisibly accepted ones.
         */
        postSallaWebhook(sallaEnvelope(), strategy: 'Telepathy')
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('accepts the token strategy when the token matches', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope(), strategy: 'Token', forcedSignature: SALLA_SECRET)
            ->assertOk()
            ->assertJsonPath('outcome', 'queued');
    });

    it('verifies over the raw body, including Arabic, rather than a re-encoding', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        /*
         * The failure this guards against is subtle and total: an implementation that decoded and
         * re-encoded the body would change the unicode escaping, and would then reject every webhook
         * from every Arabic store — which here is most of them. The fixture's product name is Arabic
         * precisely so this cannot regress unnoticed.
         */
        postSallaWebhook(sallaEnvelope())
            ->assertOk()
            ->assertJsonPath('outcome', 'queued');
    });
});

describe('routing a delivery', function (): void {
    it('drops a webhook for a merchant nobody has registered', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope(merchant: '999999999'))
            ->assertOk()
            ->assertJsonPath('outcome', 'unknown_store');

        /*
         * Answered 200 so Salla stops, and left unrecorded because without a workspace there is
         * nowhere to record it. The one case that leaves no trace, which is why the response says so.
         */
        expect(DB::table('store_webhook_events')->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('sends the order to the workspace whose secret signed it, not the first match', function (): void {
        Queue::fake();

        // Two agencies managing one shop, each with its own installed app and its own secret.
        $first = sallaWebhookScenario(secret: 'first-agency-secret');
        $second = sallaWebhookScenario(secret: 'second-agency-secret');

        postSallaWebhook(sallaEnvelope(), secret: 'second-agency-secret')
            ->assertOk()
            ->assertJsonPath('outcome', 'queued');

        $event = DB::table('store_webhook_events')->where('status', 'queued')->first();

        expect($event)->not->toBeNull();
        /** @var stdClass $event */

        /*
         * The cross-tenant leak this design exists to prevent. Routing by first match would have put
         * one agency's client's orders into the other agency's workspace, and nothing downstream could
         * have detected it.
         */
        expect($event->store_id)->toBe((string) $second['store']->getKey())
            ->and($event->workspace_id)->toBe((string) $second['scenario']->workspace->getKey())
            ->and($event->store_id)->not->toBe((string) $first['store']->getKey());
    });

    it('rejects a delivery no registered workspace can verify', function (): void {
        Queue::fake();
        sallaWebhookScenario(secret: 'first-agency-secret');
        sallaWebhookScenario(secret: 'second-agency-secret');

        postSallaWebhook(sallaEnvelope(), secret: 'neither-of-those')
            ->assertOk()
            ->assertJsonPath('outcome', 'rejected');

        Queue::assertNothingPushed();
    });

    it('answers a 404 for a provider that does not exist', function (): void {
        postSallaWebhook(sallaEnvelope())->assertOk();

        test()->postJson('/api/webhooks/stores/telepathy', [])->assertNotFound();
    });
});

describe('topics', function (): void {
    it('ignores a topic this product does not act on, without calling it a failure', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope(event: 'customer.login'))
            ->assertOk()
            ->assertJsonPath('outcome', 'ignored');

        /*
         * A merchant's app subscription delivers customer logins and cart updates too. A wall of
         * `failed` rows would make the real failures unfindable.
         */
        expect(webhookRow('store_webhook_events')->status)->toBe('ignored');
        Queue::assertNothingPushed();
    });

    it('acts on each order topic', function (): void {
        Queue::fake();
        sallaWebhookScenario();

        foreach (['order.created', 'order.updated', 'order.status.updated', 'order.refunded'] as $topic) {
            postSallaWebhook(sallaEnvelope(event: $topic))
                ->assertOk()
                ->assertJsonPath('outcome', 'queued');
        }

        Queue::assertPushed(ProcessStoreWebhookJob::class, 4);
    });
});

describe('doing the work', function (): void {
    it('writes the order the webhook described, labelled live', function (): void {
        sallaWebhookScenario();

        // Not faked: the job runs synchronously so the whole path is exercised end to end.
        postSallaWebhook(sallaEnvelope())->assertOk()->assertJsonPath('outcome', 'queued');

        $order = webhookRow('store_orders');

        expect($order->external_id)->toBe('760011001')
            ->and($order->payment_state)->toBe('paid')
            ->and($order->fulfilment_state)->toBe('delivered')
            ->and((int) $order->total_minor)->toBe(92_550)
            ->and($order->revenue_source)->toBe('salla')
            /*
             * `live` rather than `synced`: a webhook is the freshest thing this product can have, and a
             * reader deserves to see that a figure arrived that way rather than from a nightly read.
             */
            ->and($order->source)->toBe('live');

        expect(DB::table('store_order_items')->count())->toBe(1)
            ->and(webhookRow('store_webhook_events')->status)->toBe('processed');
    });

    it('is harmless when Salla redelivers, which it will', function (): void {
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope())->assertOk();
        postSallaWebhook(sallaEnvelope())->assertOk();
        postSallaWebhook(sallaEnvelope())->assertOk();

        /*
         * Salla's envelope carries no event id, so the journal cannot deduplicate it — and it does not
         * need to. The work is an upsert keyed on the order's own id, so idempotency comes from the
         * data. That is stronger than idempotency by an identifier the provider does not send.
         */
        expect(DB::table('store_orders')->count())->toBe(1)
            ->and(DB::table('store_order_items')->count())->toBe(1)
            ->and((int) DB::table('store_orders')->sum('total_minor'))->toBe(92_550)
            // Three arrivals, all recorded: the journal is a record of deliveries, not of orders.
            ->and(DB::table('store_webhook_events')->count())->toBe(3);
    });

    it('applies a later state over an earlier one', function (): void {
        sallaWebhookScenario();

        postSallaWebhook(sallaEnvelope())->assertOk();

        // The same order, refunded an hour later.
        postSallaWebhook(sallaEnvelope(order: [
            'refunds' => [['id' => 70001, 'amount' => ['amount' => 925.5, 'currency' => 'SAR'], 'date' => '2026-10-06 09:30:00']],
        ]))->assertOk();

        $order = webhookRow('store_orders');

        expect($order->payment_state)->toBe('refunded')
            ->and((int) $order->refunded_minor)->toBe(92_550)
            ->and(DB::table('store_refunds')->count())->toBe(1);
    });

    it('links the order to the customer it names once that customer is known', function (): void {
        $setup = sallaWebhookScenario();

        app(App\Application\Commerce\Contract\CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [new App\Application\Commerce\DTO\CustomerRecord(externalId: '1842003311', name: 'Layla Al-Harbi')],
        );

        postSallaWebhook(sallaEnvelope())->assertOk();

        $order = webhookRow('store_orders');
        $customer = webhookRow('store_customers');

        expect($order->store_customer_id)->toBe($customer->id)
            // Derived from the order the webhook just wrote, so the figure reconciles immediately.
            ->and((int) $customer->total_spent_minor)->toBe(92_550)
            ->and((int) $customer->orders_count)->toBe(1);
    });

    it('ignores an envelope carrying no order', function (): void {
        sallaWebhookScenario();

        postSallaWebhook([
            'event' => 'order.updated',
            'merchant' => (int) SALLA_MERCHANT,
            'created_at' => '2026-10-06 12:00:00',
            'data' => ['tracking_number' => 'SM998877'],
        ])->assertOk();

        /*
         * A shipment update whose payload names only a tracking number. Ignored rather than failed: the
         * reconciliation sync will pick the order up with its new state.
         */
        expect(DB::table('store_orders')->count())->toBe(0)
            ->and(webhookRow('store_webhook_events')->status)->toBe('ignored');
    });
});
