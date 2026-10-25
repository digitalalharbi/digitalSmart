<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\CustomerRecord;
use App\Application\Commerce\DTO\OrderItemRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\RefundRecord;
use App\Domain\Access\SystemRole;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Store analytics
|--------------------------------------------------------------------------
|
| Where the commerce domain becomes a figure somebody quotes to a client. Nearly every test here is
| about what is excluded from a total, which is the only part of a revenue report that is hard to get
| right and impossible to spot afterwards.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store} */
function analyticsScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'store-analytics-1',
        'name' => 'Noon Retail storefront',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'store' => $store];
}

/**
 * Write orders into a store.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @param  list<OrderRecord>  $orders
 */
function writeOrders(
    array $setup,
    array $orders,
    RevenueSource $revenueSource = RevenueSource::Salla,
    MetricSource $source = MetricSource::Synced,
): void {
    app(CommerceStore::class)->upsertOrders(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['store']->getKey(),
        $orders,
        $revenueSource,
        $source,
    );
}

/** @param list<OrderItemRecord> $items */
function analyticsOrder(
    string $id,
    int $total,
    PaymentState $payment = PaymentState::Paid,
    int $refunded = 0,
    ?OrderAttribution $attribution = null,
    array $items = [],
    string $placedAt = '2026-10-02 10:00:00',
    ?string $customer = null,
): OrderRecord {
    return new OrderRecord(
        externalId: $id,
        paymentState: $payment,
        fulfilmentState: FulfilmentState::Delivered,
        currency: 'SAR',
        totalMinor: $total,
        placedAt: new DateTimeImmutable($placedAt),
        attribution: $attribution ?? OrderAttribution::none(),
        refundedMinor: $refunded,
        customerExternalId: $customer,
        items: $items,
        refunds: $refunded > 0
            ? [new RefundRecord($refunded, 'SAR', new DateTimeImmutable($placedAt))]
            : [],
    );
}

function analyticsItem(string $name, int $quantity, int $unit): OrderItemRecord
{
    return new OrderItemRecord(
        name: $name,
        quantity: $quantity,
        unitPriceMinor: $unit,
        totalMinor: $unit * $quantity,
        currency: 'SAR',
        sku: strtoupper(substr($name, 0, 3)),
    );
}

/**
 * One analytics answer, over October by default.
 *
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
 * @param  array<string, string|int>  $params
 * @return array<string, mixed>
 */
function fetchAnalytics(array $setup, array $params = []): array
{
    /** @var array<string, mixed> $data */
    $data = test()->actingAs($setup['scenario']->owner)
        ->getJson('/api/commerce/analytics?'.http_build_query([
            'revenue_source' => 'salla',
            'from' => '2026-10-01',
            'until' => '2026-10-31',
            ...$params,
        ]))
        ->assertOk()
        ->json('data');

    return $data;
}

describe('naming the authority', function (): void {
    it('refuses to answer without a revenue source', function (): void {
        $setup = analyticsScenario();

        /*
         * The most important refusal in this surface. A Salla total counts invoices; a GA4 total counts
         * what a browser reported before the payment failed. Answering a request that did not say which
         * would be answering a question nobody asked — so there is no default and no "all" value.
         */
        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/commerce/analytics')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'revenue_source_required');
    });

    it('reads only orders from the authority asked for', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('salla-1', 50_000)], RevenueSource::Salla);
        writeOrders($setup, [analyticsOrder('ga4-1', 999_000)], RevenueSource::GoogleAnalytics);

        $data = fetchAnalytics($setup);

        /*
         * The GA4 order exists and is deliberately enormous. If the sources were ever summed it would be
         * obvious here — which is the point of making it obvious.
         */
        expect($data['summary']['net_revenue_minor'])->toBe(50_000)
            ->and($data['summary']['orders'])->toBe(1)
            ->and($data['summary']['revenue_source'])->toBe('salla')
            ->and($data['summary']['is_invoiced'])->toBeTrue();
    });

    it('marks a modelled source as modelled', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('attributed-1', 10_000)], RevenueSource::Attributed);

        $data = fetchAnalytics($setup, ['revenue_source' => 'attributed']);

        // So attributed revenue can never be rendered as actual.
        expect($data['summary']['is_modelled'])->toBeTrue()
            ->and($data['summary']['is_invoiced'])->toBeFalse();
    });

    it('rejects a revenue source that does not exist', function (): void {
        $setup = analyticsScenario();

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/commerce/analytics?revenue_source=vibes')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'revenue_source_required');
    });
});

describe('what counts as money', function (): void {
    it('excludes orders that are not yet paid, and reports them separately', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 50_000),
            analyticsOrder('cod-1', 30_000, PaymentState::Pending),
            analyticsOrder('cod-2', 20_000, PaymentState::Authorised),
        ]);

        $data = fetchAnalytics($setup);

        /*
         * Cash on delivery is normal in this market, so this is not an edge case — it is most of a busy
         * week. Counting those orders would overstate every report until the courier either collects or
         * comes back, and reporting them nowhere would hide real demand. So they sit beside the total.
         */
        expect($data['summary']['orders'])->toBe(1)
            ->and($data['summary']['net_revenue_minor'])->toBe(50_000)
            ->and($data['summary']['pending_orders'])->toBe(2)
            ->and($data['summary']['pending_revenue_minor'])->toBe(50_000);
    });

    it('excludes a voided order entirely', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 50_000),
            analyticsOrder('void-1', 80_000, PaymentState::Voided),
        ]);

        $data = fetchAnalytics($setup);

        // Neither money nor expected money.
        expect($data['summary']['orders'])->toBe(1)
            ->and($data['summary']['pending_orders'])->toBe(0);
    });

    it('subtracts refunds from the figure a client is shown', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 50_000),
            analyticsOrder('part-1', 40_000, PaymentState::PartiallyRefunded, refunded: 15_000),
        ]);

        $data = fetchAnalytics($setup);

        /*
         * The part refund still counts as an order, because the kept part is real money. Gross is
         * published beside net rather than instead of it, so the refund is visible rather than inferred
         * from a smaller number.
         */
        expect($data['summary']['orders'])->toBe(2)
            ->and($data['summary']['revenue_minor'])->toBe(90_000)
            ->and($data['summary']['refunded_minor'])->toBe(15_000)
            ->and($data['summary']['net_revenue_minor'])->toBe(75_000);
    });

    it('excludes a fully refunded order from revenue', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 50_000),
            analyticsOrder('refunded-1', 60_000, PaymentState::Refunded, refunded: 60_000),
        ]);

        $data = fetchAnalytics($setup);

        expect($data['summary']['orders'])->toBe(1)
            ->and($data['summary']['net_revenue_minor'])->toBe(50_000);
    });

    it('gives no average order value when nothing counted', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('cod-1', 30_000, PaymentState::Pending)]);

        $data = fetchAnalytics($setup);

        /*
         * A denominator of zero has no average. Rendering one as "0.00" would invite a reader to compare
         * it with last month's, which is the whole reason this is null.
         */
        expect($data['summary']['orders'])->toBe(0)
            ->and($data['summary']['average_order_value_minor'])->toBeNull()
            ->and($data['summary']['attribution_coverage'])->toBeNull();
    });

    it('computes an average over the orders that counted', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 50_000),
            analyticsOrder('paid-2', 30_000),
            // Must not enter the denominator.
            analyticsOrder('cod-1', 1_000_000, PaymentState::Pending),
        ]);

        $data = fetchAnalytics($setup);

        expect($data['summary']['average_order_value_minor'])->toBe(40_000);
    });

    it('counts units from the lines as sold', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('paid-1', 90_000, items: [analyticsItem('Abaya', 2, 45_000)]),
            analyticsOrder('cod-1', 20_000, PaymentState::Pending, items: [analyticsItem('Scarf', 5, 4_000)]),
        ]);

        $data = fetchAnalytics($setup);

        // The pending order's units are excluded with its revenue; a unit nobody paid for is not a sale.
        expect($data['summary']['units'])->toBe(2);
    });
});

describe('attribution coverage', function (): void {
    it('reports how much of the total can be traced to marketing at all', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('attributed-1', 60_000, attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid-launch',
            )),
            analyticsOrder('direct-1', 40_000),
        ]);

        $data = fetchAnalytics($setup);

        /*
         * Published so nobody reads a channel breakdown without knowing what share of the money it
         * covers. A report at 60% coverage is a different document from one at 95%, and the figure that
         * tells them apart belongs beside it rather than in a footnote.
         */
        expect($data['summary']['attributed_revenue_minor'])->toBe(60_000)
            // JSON renders a whole percentage without its decimal, so the comparison is on value.
            ->and((float) $data['summary']['attribution_coverage'])->toBe(60.0);
    });
});

describe('by channel', function (): void {
    it('groups on what the orders carried and names the unattributed bucket', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('m-1', 60_000, attribution: OrderAttribution::from(source: 'meta', medium: 'cpc', campaign: 'eid')),
            analyticsOrder('m-2', 20_000, attribution: OrderAttribution::from(source: 'meta', medium: 'cpc', campaign: 'eid')),
            analyticsOrder('t-1', 30_000, attribution: OrderAttribution::from(source: 'tiktok', medium: 'paid_social', campaign: 'ramadan')),
            analyticsOrder('d-1', 10_000),
        ]);

        $data = fetchAnalytics($setup);
        $labels = array_column($data['by_channel'], 'label');

        /*
         * The unattributed row is produced, not dropped. It is usually the largest, and omitting it so the
         * percentages add to a hundred is how a channel report comes to overstate every channel in it.
         */
        expect($labels)->toContain('meta / cpc')
            ->and($labels)->toContain('tiktok / paid_social')
            ->and($labels)->toContain('Unattributed');

        $meta = jsonRow($data['by_channel'], 'label', 'meta / cpc');

        expect($meta['orders'])->toBe(2)
            ->and($meta['net_revenue_minor'])->toBe(80_000)
            ->and($meta['is_unattributed'])->toBeFalse();

        expect(jsonRow($data['by_channel'], 'label', 'Unattributed')['is_unattributed'])->toBeTrue();
    });

    it('labels a row with the weakest evidence it rests on', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            // Same channel, two very different claims.
            analyticsOrder('strong-1', 50_000, attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid',
                clickIdentifier: 'fbclid-1',
            )),
            analyticsOrder('weak-1', 10_000, attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid',
            )),
        ]);

        $data = fetchAnalytics($setup);
        $row = jsonRow($data['by_channel'], 'label', 'meta / cpc');

        /*
         * Weakest rather than strongest, deliberately. A group's total is only as defensible as the
         * flimsiest link inside it, and showing the best one would flatter the row.
         */
        expect($row['evidence'])->toBe('utm_parameters')
            ->and($row['confidence'])->toBe(80);
    });
});

describe('by campaign', function (): void {
    it('only names a campaign where the evidence can name one', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('named-1', 60_000, attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid-launch',
            )),
            // A bare source names a channel, not a campaign.
            analyticsOrder('partial-1', 25_000, attribution: OrderAttribution::from(source: 'meta', medium: 'cpc')),
            analyticsOrder('direct-1', 15_000),
        ]);

        $data = fetchAnalytics($setup);
        $labels = array_column($data['by_campaign'], 'label');

        /*
         * The rule this breakdown exists under. Splitting the partial-UTM order across a client's Meta
         * campaigns would be inventing the split, so it joins the unattributed row — which therefore holds
         * both it and the direct order.
         */
        expect($labels)->toBe(['eid-launch', 'Unattributed']);

        $unattributed = jsonRow($data['by_campaign'], 'label', 'Unattributed');

        expect($unattributed['orders'])->toBe(2)
            ->and($unattributed['net_revenue_minor'])->toBe(40_000)
            ->and($unattributed['evidence'])->toBeNull();
    });

    it('credits a coupon-only order to its campaign, at a confidence that says why not to trust it', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('coupon-1', 50_000, attribution: OrderAttribution::from(couponCode: 'RAMADAN20')),
        ]);

        $data = fetchAnalytics($setup);
        $row = jsonRow($data['by_campaign'], 'label', 'RAMADAN20');

        /*
         * A coupon can name a campaign — somebody minted it for one — and a code is typed by a person who
         * may have had it from a friend, an influencer or an aggregator. So the row exists and its
         * confidence is below half, which is the honest way to show both facts at once.
         */
        expect($row['orders'])->toBe(1)
            ->and($row['evidence'])->toBe('coupon_code')
            ->and($row['confidence'])->toBeLessThan(50);
    });

    it('omits the unattributed row when everything is attributed', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('named-1', 60_000, attribution: OrderAttribution::from(
                source: 'meta',
                medium: 'cpc',
                campaign: 'eid-launch',
            )),
        ]);

        $data = fetchAnalytics($setup);

        // An empty "unattributed" row on a fully attributed report is noise.
        expect(array_column($data['by_campaign'], 'label'))->toBe(['eid-launch']);
    });
});

describe('by product and by day', function (): void {
    it('reads products from the lines as they were sold', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('o-1', 90_000, items: [analyticsItem('Abaya', 2, 45_000)]),
            analyticsOrder('o-2', 49_000, items: [
                analyticsItem('Abaya', 1, 45_000),
                analyticsItem('Scarf', 1, 4_000),
            ]),
        ]);

        $data = fetchAnalytics($setup);
        $abaya = jsonRow($data['by_product'], 'label', 'Abaya');

        expect($abaya['units'])->toBe(3)
            ->and($abaya['net_revenue_minor'])->toBe(135_000)
            ->and($abaya['orders'])->toBe(2);
    });

    it('gives a day per day of sales', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('o-1', 50_000, placedAt: '2026-10-02 10:00:00'),
            analyticsOrder('o-2', 20_000, placedAt: '2026-10-02 18:00:00'),
            analyticsOrder('o-3', 30_000, placedAt: '2026-10-05 09:00:00'),
        ]);

        $data = fetchAnalytics($setup);

        expect($data['daily'])->toHaveCount(2)
            ->and($data['daily'][0]['date'])->toBe('2026-10-02')
            ->and($data['daily'][0]['net_revenue_minor'])->toBe(70_000)
            ->and($data['daily'][1]['date'])->toBe('2026-10-05');
    });
});

describe('honesty about the rows behind a total', function (): void {
    it('labels a total read from fixtures as sandbox', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('o-1', 50_000)], source: MetricSource::Sandbox);

        $data = fetchAnalytics($setup);

        expect($data['summary']['source'])->toBe('sandbox');
    });

    it('takes the weakest label when a total spans more than one', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('live-1', 50_000)], source: MetricSource::Live);
        writeOrders($setup, [analyticsOrder('sandbox-1', 10_000)], source: MetricSource::Sandbox);

        $data = fetchAnalytics($setup);

        /*
         * A total spanning a sandbox store and a live one is a sandbox figure. Taking the best label would
         * flatter it, and this is the one number in the product that must never be flattered.
         */
        expect($data['summary']['source'])->toBe('sandbox');
    });
});

describe('access', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/commerce/analytics?revenue_source=salla')->assertUnauthorized();
    });

    it('refuses a role that cannot read analytics', function (): void {
        $setup = analyticsScenario();

        // A client's revenue is reporting, and a creative does not read reporting.
        $this->actingAs($setup['scenario']->addMember(SystemRole::Creative))
            ->getJson('/api/commerce/analytics?revenue_source=salla')
            ->assertForbidden();
    });

    it('shows nothing from a client the actor cannot reach', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [analyticsOrder('o-1', 50_000)]);

        // A buyer with the permission but no project assignment reaches no client, and so no store.
        $stranger = $setup['scenario']->addMember(SystemRole::MediaBuyer);

        $data = $this->actingAs($stranger)
            ->getJson('/api/commerce/analytics?revenue_source=salla&from=2026-10-01&until=2026-10-31')
            ->assertOk()
            ->json('data');

        expect($data['summary']['orders'])->toBe(0)
            ->and($data['summary']['net_revenue_minor'])->toBe(0);
    });

    it('does not read another workspace\'s stores', function (): void {
        $setup = analyticsScenario();
        $other = analyticsScenario();

        writeOrders($other, [analyticsOrder('other-1', 777_000)]);

        $data = fetchAnalytics($setup);

        expect($data['summary']['orders'])->toBe(0);
    });

    it('refuses a period that runs backwards', function (): void {
        $setup = analyticsScenario();

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/commerce/analytics?revenue_source=salla&from=2026-10-31&until=2026-10-01')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_period');
    });

    it('keeps an order outside the window out of the total', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            analyticsOrder('inside-1', 50_000, placedAt: '2026-10-15 10:00:00'),
            analyticsOrder('before-1', 90_000, placedAt: '2026-09-20 10:00:00'),
        ]);

        $data = fetchAnalytics($setup);

        expect($data['summary']['orders'])->toBe(1)
            ->and($data['summary']['net_revenue_minor'])->toBe(50_000);
    });

    it('includes an order placed on the last day of the window', function (): void {
        $setup = analyticsScenario();

        // 20:30 UTC on 31 October is 23:30 that evening in the store's own calendar (Riyadh, UTC+3).
        writeOrders($setup, [analyticsOrder('last-1', 50_000, placedAt: '2026-10-31 20:30:00')]);

        $data = fetchAnalytics($setup);

        // `until` is inclusive to the end of its day; a reader asking for October means all of October.
        expect($data['summary']['orders'])->toBe(1);
    });
});

describe('customers', function (): void {
    it('counts the distinct customers behind the counted orders', function (): void {
        $setup = analyticsScenario();

        app(CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [
                new CustomerRecord(externalId: 'c-1', name: 'Layla'),
                new CustomerRecord(externalId: 'c-2', name: 'Omar'),
            ],
        );

        writeOrders($setup, [
            analyticsOrder('o-1', 50_000, customer: 'c-1'),
            analyticsOrder('o-2', 20_000, customer: 'c-1'),
            analyticsOrder('o-3', 30_000, customer: 'c-2'),
            // Pending, so neither its revenue nor its customer counts.
            analyticsOrder('o-4', 10_000, PaymentState::Pending, customer: 'c-2'),
        ]);

        $data = fetchAnalytics($setup);

        expect($data['summary']['customers'])->toBe(2)
            ->and($data['summary']['orders'])->toBe(3);
    });
});

describe('new versus returning customers', function (): void {
    /**
     * A store with history before the period, and customers on both sides of it.
     *
     * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store}  $setup
     */
    function withCustomerHistory(array $setup): void
    {
        app(CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [
                new CustomerRecord(externalId: 'old', name: 'Layla'),
                new CustomerRecord(externalId: 'new', name: 'Omar'),
                new CustomerRecord(externalId: 'edge', name: 'Sara'),
            ],
        );

        writeOrders($setup, [
            /*
             * August: the **earliest** order this product holds for the store, and `edge` is the customer on it.
             * That makes `edge` the ambiguous case — their history begins exactly where the data does, so nothing
             * here can say whether they had been buying for years beforehand.
             *
             * This arrangement is the first thing this suite got wrong. The original version gave `old` and `edge`
             * the same earliest date, which made *both* of them unclassifiable — correctly, and the test that
             * expected one returning customer was asserting something the rule cannot produce. A store needs
             * history of its own before "returning" means anything, which is exactly what the metric says.
             */
            analyticsOrder('aug-edge', 25_000, placedAt: '2026-08-01 09:00:00', customer: 'edge'),

            // September: after the boundary and before the period, so `old` is genuinely a returning customer.
            analyticsOrder('sept-old', 40_000, placedAt: '2026-09-10 09:00:00', customer: 'old'),

            // October: one who bought before, one who did not, and the ambiguous one again.
            analyticsOrder('oct-old', 30_000, placedAt: '2026-10-05 09:00:00', customer: 'old'),
            analyticsOrder('oct-new', 50_000, placedAt: '2026-10-06 09:00:00', customer: 'new'),
            analyticsOrder('oct-edge', 20_000, placedAt: '2026-10-07 09:00:00', customer: 'edge'),
        ]);

        app(CommerceStore::class)->recalculateCustomerTotals(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
        );
    }

    it('counts a customer whose first order is inside the period as new', function (): void {
        $setup = analyticsScenario();
        withCustomerHistory($setup);

        $mix = fetchAnalytics($setup)['customer_mix'];

        /*
         * No window anywhere in this. A customer's first recorded order either falls inside the period or before
         * it, which is a statement about the data rather than a judgement — and that is why this metric could be
         * built without the product decision the roadmap was waiting on.
         */
        expect($mix['new_customers'])->toBe(1);
        expect($mix['new_revenue_minor'])->toBe(50_000);
    });

    it('counts a customer who bought before the period as returning', function (): void {
        $setup = analyticsScenario();
        withCustomerHistory($setup);

        $mix = fetchAnalytics($setup)['customer_mix'];

        expect($mix['returning_customers'])->toBe(1);
        /* Only the October order. The September one is outside the period and belongs to no figure here. */
        expect($mix['returning_revenue_minor'])->toBe(30_000);
    });

    it('refuses to call a customer new when their history predates the data', function (): void {
        $setup = analyticsScenario();
        withCustomerHistory($setup);

        $mix = fetchAnalytics($setup)['customer_mix'];

        /*
         * The case that makes this metric publishable. `edge`'s first recorded order is the earliest order this
         * product holds for the store, so they may have been buying for years before the integration existed.
         * Counting them as new would invent new customers on day one of every integration — the figure would look
         * spectacular and be worthless.
         */
        expect($mix['unknown_customers'])->toBe(1);
        expect($mix['unknown_revenue_minor'])->toBe(20_000);
    });

    it('withholds the share while too much of the period cannot be classified', function (): void {
        $setup = analyticsScenario();
        withCustomerHistory($setup);

        $mix = fetchAnalytics($setup)['customer_mix'];

        /*
         * 20,000 of 100,000 is a fifth, which is the ceiling — a share over a denominator that is substantially
         * "we cannot tell" is a figure somebody puts in a report. The sentence goes where the percentage would
         * have been, and says that it becomes available as the store accumulates its own history.
         */
        expect($mix['new_share'])->toBeNull();
        expect($mix['unavailable_reason'])->toContain('before this store was connected');
    });

    it('reports the share once the unknown bucket is small enough to ignore', function (): void {
        $setup = analyticsScenario();
        withCustomerHistory($setup);

        /* More October revenue from a clearly-new customer, which pushes the unknown share under the ceiling. */
        app(CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [new CustomerRecord(externalId: 'new-2', name: 'Faisal')],
        );

        writeOrders($setup, [
            analyticsOrder('oct-new-2', 400_000, placedAt: '2026-10-08 09:00:00', customer: 'new-2'),
        ]);

        app(CommerceStore::class)->recalculateCustomerTotals(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
        );

        $mix = fetchAnalytics($setup)['customer_mix'];

        expect($mix['unavailable_reason'])->toBeNull();
        expect($mix['new_share'])->toBeGreaterThan(0.8);
    });

    it('says nothing was sold rather than reporting a share of nothing', function (): void {
        $setup = analyticsScenario();

        $mix = fetchAnalytics($setup)['customer_mix'];

        // Two different reasons for no share, and the surface says which. Never 0%.
        expect($mix['new_share'])->toBeNull();
        expect($mix['unavailable_reason'])->toContain('Nothing was sold');
    });

    it('leaves an order with no customer out of the mix entirely', function (): void {
        $setup = analyticsScenario();

        writeOrders($setup, [
            // A guest checkout the provider attached to nobody.
            analyticsOrder('guest', 70_000, placedAt: '2026-10-09 09:00:00'),
        ]);

        $mix = fetchAnalytics($setup)['customer_mix'];

        /*
         * Left out rather than bucketed as unknown: "we have no customer here" is not the same claim as "we
         * cannot tell when this customer started", and folding them together would make the unknown bucket a
         * measure of guest checkouts.
         */
        expect($mix['new_customers'] + $mix['returning_customers'] + $mix['unknown_customers'])->toBe(0);
        expect($mix['unavailable_reason'])->toContain('Nothing was sold');
    });

    it('publishes the three buckets even where one is empty', function (): void {
        $setup = analyticsScenario();

        $buckets = array_column(fetchAnalytics($setup)['customer_mix']['buckets'], 'recency');

        // A split that only mentioned "we cannot tell" when it was large would teach a reader it never happens.
        expect($buckets)->toBe(['new', 'returning', 'unknown']);
    });
});

/*
|--------------------------------------------------------------------------
| The store's own day (DS-UC-202)
|--------------------------------------------------------------------------
|
| Instants are stored as UTC. A store sells in its own calendar, so a period is cut in the store's own time zone: "2 October" for a
| Riyadh store runs from 21:00 UTC on 1 October. Before this the period and the daily series were cut at UTC midnight, which moved the
| first three hours of every Riyadh day onto the day before — and moved a month's first evening into the previous month.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, store: Store} */
function dayCutScenario(?string $timezone = 'Asia/Riyadh'): array
{
    $setup = analyticsScenario();
    $setup['store']->forceFill(['timezone' => $timezone])->save();

    return $setup;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, int>
 */
function ordersByDay(array $data): array
{
    /** @var list<array{date: string, orders: int}> $daily */
    $daily = $data['daily'];

    return array_column($daily, 'orders', 'date');
}

describe('the store’s own day', function (): void {
    it('puts the first evening hours of a Riyadh day on that day, not the day before', function (): void {
        $setup = dayCutScenario();
        // 22:30 UTC on 1 October is 01:30 on 2 October in Riyadh.
        writeOrders($setup, [analyticsOrder('late', 10_000, placedAt: '2026-10-01 22:30:00')]);

        expect(ordersByDay(fetchAnalytics($setup)))->toBe(['2026-10-02' => 1]);
    });

    it('includes that order in 2 October and not in 1 October when the period is a single day', function (): void {
        $setup = dayCutScenario();
        writeOrders($setup, [analyticsOrder('late', 10_000, placedAt: '2026-10-01 22:30:00')]);

        $second = fetchAnalytics($setup, ['from' => '2026-10-02', 'until' => '2026-10-02']);
        $first = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-01']);

        expect($second['summary']['orders'])->toBe(1)->and($first['summary']['orders'])->toBe(0);
    });

    it('cuts exactly at the store’s local midnight', function (): void {
        $setup = dayCutScenario();
        writeOrders($setup, [
            // 20:59:59 UTC is 23:59:59 on 1 October in Riyadh; 21:00:00 UTC is 00:00:00 on 2 October.
            analyticsOrder('last-second', 10_000, placedAt: '2026-10-01 20:59:59'),
            analyticsOrder('first-second', 20_000, placedAt: '2026-10-01 21:00:00'),
        ]);

        expect(ordersByDay(fetchAnalytics($setup)))->toBe(['2026-10-01' => 1, '2026-10-02' => 1]);
    });

    it('moves a month’s first Riyadh evening into that month', function (): void {
        $setup = dayCutScenario();
        writeOrders($setup, [analyticsOrder('october-first', 10_000, placedAt: '2026-09-30 21:30:00')]);

        $october = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-31']);
        $september = fetchAnalytics($setup, ['from' => '2026-09-01', 'until' => '2026-09-30']);

        expect($october['summary']['orders'])->toBe(1)->and($september['summary']['orders'])->toBe(0);
    });

    it('cuts a store with no time zone at UTC midnight', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [analyticsOrder('late', 10_000, placedAt: '2026-10-01 22:30:00')]);

        expect(ordersByDay(fetchAnalytics($setup)))->toBe(['2026-10-01' => 1]);
    });

    it('cuts each store in its own zone when a client has stores in two', function (): void {
        $setup = dayCutScenario();
        /** @var Store $dubai */
        $dubai = Store::query()->create([
            'workspace_id' => $setup['scenario']->workspace->getKey(), 'client_id' => $setup['client']->getKey(),
            'provider' => Provider::Salla->value, 'external_id' => 'store-dubai', 'name' => 'Dubai', 'currency' => 'SAR',
            'timezone' => 'Asia/Dubai',
        ]);
        writeOrders($setup, [analyticsOrder('riyadh', 10_000, placedAt: '2026-10-01 21:30:00')]);
        app(CommerceStore::class)->upsertOrders(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $dubai->getKey(),
            [analyticsOrder('dubai', 10_000, placedAt: '2026-10-01 21:30:00')],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        // 21:30 UTC is 00:30 on 2 October in Riyadh (+3) but 01:30 on 2 October in Dubai (+4): both on the 2nd, each by its own rule.
        expect(ordersByDay(fetchAnalytics($setup)))->toBe(['2026-10-02' => 2]);
        // And 20:30 UTC is 23:30 on the 1st in Riyadh but 00:30 on the 2nd in Dubai: split across two days.
        writeOrders($setup, [analyticsOrder('riyadh-early', 5_000, placedAt: '2026-10-01 20:30:00')]);
        app(CommerceStore::class)->upsertOrders(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $dubai->getKey(),
            [analyticsOrder('dubai-early', 5_000, placedAt: '2026-10-01 20:30:00')],
            RevenueSource::Salla,
            MetricSource::Synced,
        );
        expect(ordersByDay(fetchAnalytics($setup)))->toBe(['2026-10-01' => 1, '2026-10-02' => 3]);
    });

    it('keeps the summary, the ledger and the daily series on one calendar', function (): void {
        $setup = dayCutScenario();
        writeOrders($setup, [
            analyticsOrder('a', 10_000, placedAt: '2026-10-01 20:30:00'),
            analyticsOrder('b', 20_000, placedAt: '2026-10-01 21:30:00'),
            analyticsOrder('c', 40_000, placedAt: '2026-10-31 20:30:00'),
            analyticsOrder('d', 80_000, placedAt: '2026-10-31 21:30:00'),
        ]);

        $data = fetchAnalytics($setup);

        // 31 October 21:30 UTC is 1 November in Riyadh, so it is outside October; 1 October 20:30 UTC is still 1 October inside it.
        expect($data['summary']['orders'])->toBe(3)
            ->and(array_sum(ordersByDay($data)))->toBe(3)
            ->and(array_sum(array_column($data['daily'], 'net_revenue_minor')))->toBe($data['summary']['net_revenue_minor'])
            ->and($data['summary']['net_revenue_minor'])->toBe(70_000);
    });

    it('classifies a customer new or returning by the store’s own day', function (): void {
        $setup = dayCutScenario();
        app(CommerceStore::class)->upsertCustomers(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [new CustomerRecord(externalId: 'cust-0', name: 'Layla'), new CustomerRecord(externalId: 'cust-1', name: 'Omar')],
        );
        $first = new OrderRecord(
            externalId: 'first', paymentState: PaymentState::Paid, fulfilmentState: FulfilmentState::Delivered, currency: 'SAR',
            totalMinor: 10_000, placedAt: new DateTimeImmutable('2026-09-30 21:30:00'), attribution: OrderAttribution::none(),
            customerExternalId: 'cust-1',
        );
        $earlier = new OrderRecord(
            externalId: 'earlier', paymentState: PaymentState::Paid, fulfilmentState: FulfilmentState::Delivered, currency: 'SAR',
            totalMinor: 5_000, placedAt: new DateTimeImmutable('2026-08-15 10:00:00'), attribution: OrderAttribution::none(),
            customerExternalId: 'cust-0',
        );
        writeOrders($setup, [$earlier, $first]);
        app(CommerceStore::class)->recalculateCustomerTotals(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
        );

        $mix = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-31'])['customer_mix'];

        // cust-1's first order is 00:30 on 1 October in Riyadh: new this month, not a September customer.
        expect($mix['new_customers'])->toBe(1)->and($mix['returning_customers'])->toBe(0);
    });

    it('stores a real zone name, and nothing for a name it does not know', function (string $given, ?string $stored): void {
        $setup = analyticsScenario();

        $view = app(App\Application\Commerce\Contract\StoreDirectory::class)->register(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['client']->getKey(),
            Provider::Zid,
            new App\Application\Commerce\DTO\StoreRecord(externalId: 'tz-'.md5($given), name: 'Zone', currency: 'SAR', timezone: $given),
            null,
        );

        // A name the database does not know would fail every analytics query for the client, and it comes from a provider's payload.
        expect($view)->not->toBeNull()
            ->and(DB::table('stores')->where('external_id', 'tz-'.md5($given))->value('timezone'))->toBe($stored);
    })->with([
        'a real zone' => ['Asia/Riyadh', 'Asia/Riyadh'],
        'an invented one' => ['Mars/Olympus_Mons', null],
        'an offset, which is not a zone name' => ['+03:00', null],
    ]);
});

/*
|--------------------------------------------------------------------------
| Trends and the previous period (DS-UC-202)
|--------------------------------------------------------------------------
|
| A trend is read at a grain; a change is read against a period that can be compared with it. Weeks start on Monday (ISO) and say so.
| A bucket the window cuts through — the week that began before the period, the month that ends after it — is flagged partial rather
| than drawn as if it were whole, because a short bucket beside full ones reads as a fall. The previous period is the same number of
| days immediately before; where there is nothing to compare with, the change is absent and says why, never zero.
|
*/

/**
 * @param  array<string, mixed>  $data
 * @return array<string, array{orders: int, partial: bool}>
 */
function seriesOf(array $data): array
{
    /** @var list<array{date: string, orders: int, is_partial: bool}> $points */
    $points = $data['series']['points'];

    return array_combine(
        array_column($points, 'date'),
        array_map(static fn (array $p): array => ['orders' => $p['orders'], 'partial' => $p['is_partial']], $points),
    );
}

describe('the grain of a trend', function (): void {
    it('groups by ISO week, beginning on Monday, and names its grain', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('mon', 10_000, placedAt: '2026-10-05 10:00:00'),
            analyticsOrder('sun', 10_000, placedAt: '2026-10-11 20:00:00'),
            analyticsOrder('next-mon', 10_000, placedAt: '2026-10-12 08:00:00'),
        ]);

        $data = fetchAnalytics($setup, ['grain' => 'week']);

        expect($data['series']['grain'])->toBe('week')
            ->and(seriesOf($data))->toBe(['2026-10-05' => ['orders' => 2, 'partial' => false], '2026-10-12' => ['orders' => 1, 'partial' => false]]);
    });

    it('flags the bucket a window cuts through, and only that one', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('first-week', 10_000, placedAt: '2026-10-01 10:00:00'),   // Thursday: its week began 28 September
            analyticsOrder('middle', 10_000, placedAt: '2026-10-14 10:00:00'),
            analyticsOrder('last-week', 10_000, placedAt: '2026-10-30 10:00:00'),    // its week ends 1 November
        ]);

        $partial = array_map(static fn (array $b): bool => $b['partial'], seriesOf(fetchAnalytics($setup, ['grain' => 'week'])));

        expect($partial)->toBe(['2026-09-28' => true, '2026-10-12' => false, '2026-10-26' => true]);
    });

    it('groups by month, and calls a whole month whole', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('sep', 10_000, placedAt: '2026-09-20 10:00:00'),
            analyticsOrder('oct', 20_000, placedAt: '2026-10-10 10:00:00'),
        ]);

        $cut = seriesOf(fetchAnalytics($setup, ['grain' => 'month', 'from' => '2026-09-15', 'until' => '2026-10-15']));
        $whole = seriesOf(fetchAnalytics($setup, ['grain' => 'month', 'from' => '2026-10-01', 'until' => '2026-10-31']));

        expect($cut)->toBe(['2026-09-01' => ['orders' => 1, 'partial' => true], '2026-10-01' => ['orders' => 1, 'partial' => true]])
            ->and($whole)->toBe(['2026-10-01' => ['orders' => 1, 'partial' => false]]);
    });

    it('buckets by the store’s own day', function (): void {
        $setup = dayCutScenario();
        // 21:30 UTC on 30 September is 00:30 on 1 October in Riyadh: October's month, not September's.
        writeOrders($setup, [analyticsOrder('edge', 10_000, placedAt: '2026-09-30 21:30:00')]);

        $series = seriesOf(fetchAnalytics($setup, ['grain' => 'month', 'from' => '2026-09-01', 'until' => '2026-10-31']));

        expect(array_keys($series))->toBe(['2026-10-01']);
    });

    it('defaults to days, keeps the daily series as it was, and refuses a grain it does not have', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [analyticsOrder('a', 10_000, placedAt: '2026-10-05 10:00:00')]);

        $data = fetchAnalytics($setup);

        expect($data['series']['grain'])->toBe('day')->and(array_column($data['series']['points'], 'date'))->toBe(array_column($data['daily'], 'date'));

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/commerce/analytics?revenue_source=salla&grain=fortnight')
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_grain');
    });
});

describe('the previous period', function (): void {
    it('is absent unless asked for', function (): void {
        $setup = dayCutScenario(null);

        expect(fetchAnalytics($setup))->not->toHaveKey('comparison');
    });

    it('is the same number of days immediately before, and reports the change as a percentage', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            // Previous: 24–30 September (7 days). Current: 1–7 October (7 days).
            analyticsOrder('p1', 20_000, placedAt: '2026-09-25 10:00:00'),
            analyticsOrder('p2', 10_000, placedAt: '2026-09-28 10:00:00'),
            analyticsOrder('c1', 30_000, placedAt: '2026-10-02 10:00:00'),
            analyticsOrder('c2', 30_000, placedAt: '2026-10-05 10:00:00'),
            analyticsOrder('c3', 30_000, placedAt: '2026-10-06 10:00:00'),
        ]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        expect($c['previous']['from'])->toBe('2026-09-24')->and($c['previous']['until'])->toBe('2026-09-30')
            ->and($c['previous']['orders'])->toBe(2)->and($c['previous']['net_revenue_minor'])->toBe(30_000)
            ->and($c['previous']['average_order_value_minor'])->toBe(15_000)
            ->and($c['unavailable_reason'])->toBeNull()
            // 30 000 → 90 000 is +200%; 2 → 3 orders is +50%; 15 000 → 30 000 average is +100%.
            ->and($c['change']['net_revenue_pct'])->toEqual(200.0)
            ->and($c['change']['orders_pct'])->toEqual(50.0)
            ->and($c['change']['average_order_value_pct'])->toEqual(100.0);
    });

    it('reports a fall as a negative change', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('p', 40_000, placedAt: '2026-09-25 10:00:00'),
            analyticsOrder('c', 30_000, placedAt: '2026-10-02 10:00:00'),
        ]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        expect($c['change']['net_revenue_pct'])->toEqual(-25.0);
    });

    it('gives no change, and says why, when nothing was sold in the period before', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('early', 5_000, placedAt: '2026-08-01 10:00:00'),   // the store has history, just not in the previous week
            analyticsOrder('c', 30_000, placedAt: '2026-10-02 10:00:00'),
        ]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        // "Up from nothing" has no percentage, and +∞ is a number somebody will quote.
        expect($c['unavailable_reason'])->toBe('nothing_sold_before')
            ->and($c['change']['net_revenue_pct'])->toBeNull()->and($c['change']['orders_pct'])->toBeNull()
            ->and($c['previous']['orders'])->toBe(0);
    });

    it('says the period before predates the data, rather than that nothing was sold', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [analyticsOrder('c', 30_000, placedAt: '2026-10-02 10:00:00')]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        // The first order this product holds is in the current period: the store was not being read before it.
        expect($c['unavailable_reason'])->toBe('before_the_data')->and($c['change']['net_revenue_pct'])->toBeNull();
    });

    it('gives no average-order change when either period has no average', function (): void {
        $setup = dayCutScenario(null);
        writeOrders($setup, [
            analyticsOrder('p', 10_000, placedAt: '2026-09-25 10:00:00', payment: PaymentState::Pending),
            analyticsOrder('early', 5_000, placedAt: '2026-08-01 10:00:00'),
            analyticsOrder('c', 30_000, placedAt: '2026-10-02 10:00:00'),
        ]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        expect($c['previous']['average_order_value_minor'])->toBeNull()->and($c['change']['average_order_value_pct'])->toBeNull();
    });

    it('is cut in the store’s own day, like the period it is compared with', function (): void {
        $setup = dayCutScenario();
        writeOrders($setup, [
            // 21:30 UTC on 30 September is 00:30 on 1 October in Riyadh: the current period, not the previous one.
            analyticsOrder('edge', 30_000, placedAt: '2026-09-30 21:30:00'),
            analyticsOrder('before', 10_000, placedAt: '2026-09-25 10:00:00'),
        ]);

        $c = fetchAnalytics($setup, ['from' => '2026-10-01', 'until' => '2026-10-07', 'compare' => 'previous'])['comparison'];

        expect($c['previous']['orders'])->toBe(1)->and($c['previous']['net_revenue_minor'])->toBe(10_000);
    });

    it('refuses a comparison it does not have', function (): void {
        $setup = dayCutScenario(null);

        $this->actingAs($setup['scenario']->owner)
            ->getJson('/api/commerce/analytics?revenue_source=salla&compare=last_year')
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_comparison');
    });
});
