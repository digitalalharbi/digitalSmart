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

        writeOrders($setup, [analyticsOrder('last-1', 50_000, placedAt: '2026-10-31 22:30:00')]);

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
