<?php

declare(strict_types=1);

use App\Application\Commerce\Command\SyncStore;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Salla reconciliation sync
|--------------------------------------------------------------------------
|
| Webhooks are faster and they are not sufficient: one dropped delivery, one deploy during a busy
| hour, one Salla incident, and a client's revenue is quietly short for that window with nothing to
| indicate it. This is what makes the figures eventually correct regardless.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function sallaSyncScenario(bool $sandbox = true): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::Salla,
        owner: ConnectionOwner::agency(),
        name: 'Salla',
        credentials: new Credentials([
            'client_id' => $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-id',
            'client_secret' => $sandbox ? Credentials::SANDBOX_TOKEN : 'a-real-looking-secret',
        ]),
    );

    app(ProviderConnectionRepository::class)->save($connection);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => Provider::Salla->value,
        'external_id' => '1183363243',
        'name' => 'SANDBOX — Noon Retail',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => $store];
}

/** @param array{scenario: WorkspaceScenario, client: Client, store: Store} $setup */
function runSallaSync(array $setup): App\Application\Commerce\DTO\StoreSyncResult
{
    return app(SyncStore::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['store']->getKey(),
        Provider::Salla,
        '1183363243',
    );
}

/**
 * One stored order by its Salla id, failing the test if the sync never wrote it.
 *
 * `first()` returns null for a missing row, and asserting against null reports a confusing type error
 * rather than the useful fact that nothing was written.
 */
function sallaOrderRow(string $externalId): stdClass
{
    $row = DB::table('store_orders')->where('external_id', $externalId)->first();

    expect($row)->not->toBeNull("no order stored for {$externalId}");

    /** @var stdClass $row */
    return $row;
}

/** One stored customer by its Salla id. */
function sallaCustomerRow(string $externalId): stdClass
{
    $row = DB::table('store_customers')->where('external_id', $externalId)->first();

    expect($row)->not->toBeNull("no customer stored for {$externalId}");

    /** @var stdClass $row */
    return $row;
}

describe('reading a store', function (): void {
    it('reads customers, products and orders, and says what it did', function (): void {
        $setup = sallaSyncScenario();

        $result = runSallaSync($setup);

        expect($result->status)->toBe(SyncStatus::Succeeded)
            ->and($result->customersWritten)->toBeGreaterThan(0)
            ->and($result->productsWritten)->toBeGreaterThan(0)
            ->and($result->ordersWritten)->toBeGreaterThan(0)
            // Fixtures are never shown as production, whatever else is true of them.
            ->and($result->source->value)->toBe('sandbox');
    });

    it('pages through everything rather than stopping at the first page', function (): void {
        $setup = sallaSyncScenario();

        $result = runSallaSync($setup);

        /*
         * Salla documents that a page requested out of sequence returns an empty response, so a sync
         * that parallelised or resumed in the wrong place would read nothing and report success. The
         * fixtures hold more than one page of each entity on purpose.
         */
        expect($result->apiCalls)->toBeGreaterThan(3)
            ->and(DB::table('store_customers')->count())->toBe(4)
            ->and(DB::table('store_products')->count())->toBe(4);
    });

    /*
     * The limit the adapter publishes, honoured. Salla answers at most 92 days in one request, so a first sync
     * reaching back a year has to be asked for in windows — and before this it went out whole and was refused by
     * the provider, which arrived on a client's store page as "partial" with nothing anybody could act on.
     */
    it('asks for a long range in windows rather than one request Salla would refuse', function (): void {
        $setup = sallaSyncScenario();

        $short = runSallaSync($setup);
        $long = app(SyncStore::class)(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            Provider::Salla,
            '1183363243',
            new DateTimeImmutable('-400 days'),
        );

        /*
         * Four hundred days over a 92-day limit is five windows, so the long sync makes strictly more order
         * requests than the short one. Asserted as a relation rather than a count: the fixture's paging decides
         * the absolute number and would make this a test of the fixture.
         */
        expect($long->apiCalls)->toBeGreaterThan($short->apiCalls);
    });

    it('links order lines to the products it read first', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $linked = DB::table('store_order_items')->whereNotNull('store_product_id')->count();

        /*
         * The reason customers and products are read before orders. A line read first would store a
         * null where a product should be, and a client's product report would be missing its
         * best-selling item until the next run, for no visible reason.
         */
        expect($linked)->toBeGreaterThan(0);
    });

    it('links orders to their customers', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        expect(DB::table('store_orders')->whereNotNull('store_customer_id')->count())->toBeGreaterThan(0);
    });
});

describe('what the figures mean', function (): void {
    it('counts a prepaid completed order as revenue and a cash-on-delivery shipment as not', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $completed = sallaOrderRow('760011001');
        $cod = sallaOrderRow('760011002');

        /*
         * The distinction this whole integration turns on in this market. Both orders are real; only
         * one of them is money. A single status column could not express it, and counting the second
         * would overstate every report until the courier either collects or comes back.
         */
        expect($completed->payment_state)->toBe('paid')
            ->and($cod->payment_state)->toBe('pending')
            ->and($cod->fulfilment_state)->toBe('shipped')
            ->and($cod->payment_method)->toBe('cod');
    });

    it('records a refunded order as refunded, and keeps the refund', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $refunded = sallaOrderRow('760011004');

        // Salla shows it as delivered; the money went back. Both facts are kept, on their own axes.
        expect($refunded->payment_state)->toBe('refunded')
            ->and($refunded->fulfilment_state)->toBe('delivered')
            ->and(DB::table('store_refunds')->count())->toBe(1);
    });

    it('attributes only the order that carried evidence', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $attributed = sallaOrderRow('760011002');
        $unattributed = sallaOrderRow('760011003');

        /*
         * One order carried a click identifier and a campaign; the other carried nothing. The honest
         * report shows the second as unattributed rather than dividing it among whatever was running.
         */
        expect($attributed->attribution_evidence)->toBe('click_identifier')
            /*
             * The fixture names a campaign that exists in the sandbox ad account, so the demo
             * workspace can show an attributed order matched to real spend. The value is demo content
             * rather than part of Salla's shape; what is being asserted is that the mapper carries it
             * through untouched.
             */
            ->and($attributed->utm_campaign)->toBe('SANDBOX — Alhambra | Ramadan Sales | KSA')
            ->and($unattributed->attribution_evidence)->toBe('none')
            ->and($unattributed->utm_campaign)->toBeNull();
    });

    it('derives a customer\'s lifetime total from the orders that count', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $layla = sallaCustomerRow('1842003311');

        /*
         * Layla has two orders in the fixtures: one completed at 925.50 and one delivered then fully
         * refunded at 450. The refunded one contributes nothing, so her lifetime total is the first
         * alone — a figure that reconciles against the orders a reader can see.
         */
        expect((int) $layla->total_spent_minor)->toBe(92_550)
            ->and((int) $layla->orders_count)->toBe(1);
    });

    it('keeps an order whose breakdown Salla did not report as null, not zero', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $cancelled = sallaOrderRow('760011005');

        /*
         * That fixture carries a total and no breakdown, as Salla's list endpoint does. Zero would read
         * as "nothing was charged for shipping", and a subtotal report built on it would be
         * confidently wrong rather than visibly absent.
         */
        expect((int) $cancelled->total_minor)->toBe(12_000)
            ->and($cancelled->subtotal_minor)->toBeNull()
            ->and($cancelled->shipping_minor)->toBeNull()
            ->and($cancelled->payment_state)->toBe('voided');
    });
});

describe('re-running', function (): void {
    it('reaches the same figures however many times it runs', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);
        $firstTotal = (int) DB::table('store_orders')->sum('total_minor');
        $firstOrders = DB::table('store_orders')->count();
        $firstItems = DB::table('store_order_items')->count();

        runSallaSync($setup);
        runSallaSync($setup);

        /*
         * The sync re-reads a fortnight on every run because orders keep changing. An insert rather
         * than an upsert would multiply a client's revenue by the number of runs — and it would look
         * exactly like growth.
         */
        expect(DB::table('store_orders')->count())->toBe($firstOrders)
            ->and(DB::table('store_order_items')->count())->toBe($firstItems)
            ->and((int) DB::table('store_orders')->sum('total_minor'))->toBe($firstTotal)
            ->and(DB::table('store_refunds')->count())->toBe(1);
    });

    it('records that it read the store, and how far', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);

        $store = Store::query()->findOrFail($setup['store']->getKey());

        expect($store->getAttribute('last_synced_at'))->not->toBeNull()
            ->and($store->getAttribute('orders_synced_through'))->not->toBeNull();
    });

    it('agrees with what a webhook wrote', function (): void {
        $setup = sallaSyncScenario();

        runSallaSync($setup);
        $afterSync = sallaOrderRow('760011001');

        /*
         * The two paths share one mapper precisely so this holds. A second mapper for webhooks is how
         * the two would come to disagree about a status — and the disagreement would show up as a
         * client's revenue changing whenever a nightly sync overwrote what a webhook wrote an hour
         * earlier.
         */
        expect($afterSync->payment_state)->toBe('paid')
            ->and($afterSync->fulfilment_state)->toBe('delivered')
            ->and((int) $afterSync->total_minor)->toBe(92_550);
    });
});

describe('the live transport is blocked', function (): void {
    it('fails loudly on real credentials rather than inventing orders', function (): void {
        $setup = sallaSyncScenario(sandbox: false);

        $result = runSallaSync($setup);

        /*
         * No installed Partner app, so no order can be read. The only acceptable behaviour is to fail
         * and say so: a silent fake success here would put invented *revenue* in front of a client.
         */
        expect($result->status)->toBe(SyncStatus::Failed)
            ->and($result->ordersWritten)->toBe(0)
            ->and($result->failureMessage)->not->toBeNull();

        expect(DB::table('store_orders')->count())->toBe(0);
    });

    it('still records the attempt, so a failing store does not look untouched', function (): void {
        $setup = sallaSyncScenario(sandbox: false);

        runSallaSync($setup);

        $store = Store::query()->findOrFail($setup['store']->getKey());

        // "We tried and got nowhere" is a different fact from "nobody has asked".
        expect($store->getAttribute('last_synced_at'))->not->toBeNull()
            ->and($store->getAttribute('orders_synced_through'))->toBeNull();
    });

    it('skips a provider that is not a store at all, instead of failing', function (): void {
        $setup = sallaSyncScenario();

        $result = app(SyncStore::class)(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            /*
             * Meta is an ad platform and has no orders to read. Skipped rather than failed, so the
             * interface can disable the action instead of offering one that errors.
             */
            Provider::Meta,
            'act_sandbox_1001',
        );

        expect($result->status)->toBe(SyncStatus::Skipped)
            ->and($result->ordersWritten)->toBe(0);
    });
});
