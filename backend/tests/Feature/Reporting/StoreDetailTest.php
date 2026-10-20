<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\OrderItemRecord;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\RefundRecord;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\FulfilmentState;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Commerce\PaymentState;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| What sits behind a store section's figures
|--------------------------------------------------------------------------
|
| The trend, the stores, the products, the channels, the customers and the refunds — all for the one authority the
| section names, and none of it able to name a person. What is asserted: the authority is never crossed, a missing day
| is a gap and not a zero, a comparison exists only with something to compare, a rate has a valid denominator, and
| nothing in the payload can lead back to a customer, an order or a store record.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function detailScenario(string $name = 'Noon Retail storefront', string $external = 'detail-1'): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => detailStore($scenario, $client, $name, $external)];
}

function detailStore(WorkspaceScenario $scenario, Client $client, string $name, string $external, string $currency = 'SAR'): Store
{
    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => $external,
        'name' => $name,
        'currency' => $currency,
        'timezone' => 'Asia/Riyadh',
    ]);

    return $store;
}

/** @param list<OrderItemRecord> $items */
function detailOrder(
    string $id,
    int $total,
    string $daysAgo,
    int $refunded = 0,
    array $items = [],
    ?string $customer = null,
    ?OrderAttribution $attribution = null,
    string $currency = 'SAR',
): OrderRecord {
    $placedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("-{$daysAgo} days");

    return new OrderRecord(
        externalId: $id,
        paymentState: PaymentState::Paid,
        fulfilmentState: FulfilmentState::Delivered,
        currency: $currency,
        totalMinor: $total,
        placedAt: $placedAt,
        attribution: $attribution ?? OrderAttribution::none(),
        refundedMinor: $refunded,
        customerExternalId: $customer ?? $id,
        items: $items,
        refunds: $refunded > 0 ? [new RefundRecord($refunded, $currency, $placedAt)] : [],
    );
}

function detailItem(string $name, int $quantity, int $unit): OrderItemRecord
{
    return new OrderItemRecord($name, $quantity, $unit, $unit * $quantity, 'SAR');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, store: Store}  $setup
 * @param  list<OrderRecord>  $orders
 */
function detailWrite(array $setup, array $orders, RevenueSource $authority = RevenueSource::Salla, ?Store $store = null): void
{
    app(CommerceStore::class)->upsertOrders(
        (string) $setup['scenario']->workspace->getKey(),
        (string) ($store ?? $setup['store'])->getKey(),
        $orders,
        $authority,
        MetricSource::Synced,
    );
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, store: Store}  $setup
 * @return array<string, mixed>
 */
function detailRender(array $setup, string $comparison = 'none', string $source = 'salla'): array
{
    $report = (string) test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Commerce',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'comparison' => $comparison,
            'audience' => 'client_safe',
            'sections' => [['kind' => 'store', 'revenue_source' => $source]],
        ])
        ->assertCreated()
        ->json('data.id');

    /** @var array<string, mixed> $section */
    $section = test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$report}")->assertOk()->json('data.sections.0');

    return $section;
}

describe('the trend', function (): void {
    it('is one authority\'s days, with a day nobody ordered on left out rather than zeroed', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [detailOrder('a', 10_000, '5'), detailOrder('b', 4_000, '5'), detailOrder('c', 7_000, '2')]);
        detailWrite($setup, [detailOrder('g', 999_000, '4')], RevenueSource::GoogleAnalytics);

        $detail = detailRender($setup)['store_detail'];
        $days = array_column($detail['daily'], 'net_revenue', 'date');

        expect($days)->toHaveCount(2)
            ->and(array_values($days))->toBe([14_000, 7_000])
            ->and(array_sum($days))->toBe(21_000);
    });

    it('never carries the other authority\'s money', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [detailOrder('a', 5_000, '3')]);
        detailWrite($setup, [detailOrder('g', 999_000, '3')], RevenueSource::GoogleAnalytics);

        $detail = detailRender($setup)['store_detail'];

        expect(json_encode($detail))->not->toContain('999000');
    });
});

describe('the refunds', function (): void {
    it('rates the money refunded against gross revenue and counts orders with a refund', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [detailOrder('a', 10_000, '5', refunded: 1_000), detailOrder('b', 10_000, '4')]);

        $refunds = detailRender($setup)['store_detail']['refunds'];

        expect($refunds['amount'])->toBe(1_000)
            ->and($refunds['orders'])->toBe(1)
            // 1,000 of 20,000 gross — not of the 19,000 net.
            ->and($refunds['rate'])->toEqual(5);
    });

    it('has no rate where nothing was sold, rather than a clean 0%', function (): void {
        $setup = detailScenario();

        $refunds = detailRender($setup)['store_detail']['refunds'];

        expect($refunds['rate'])->toBeNull();
    });

    it('compares with the earlier period only when one was asked for', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [
            detailOrder('now', 10_000, '5', refunded: 2_000),
            detailOrder('before', 10_000, '40', refunded: 500),
        ]);

        $without = detailRender($setup)['store_detail'];
        $with = detailRender($setup, 'previous_period')['store_detail'];

        expect($without['previous'])->toBeNull()
            ->and($without['refunds']['previous_rate'])->toBeNull()
            ->and($with['refunds']['rate'])->toEqual(20)
            ->and($with['refunds']['previous_rate'])->toEqual(5)
            ->and($with['previous']['orders'])->toBe(1);
    });
});

describe('the stores', function (): void {
    it('shows no comparison for one store', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [detailOrder('a', 5_000, '3')]);

        expect(detailRender($setup)['store_detail']['stores'])->toBe([]);
    });

    it('compares several, with shares that add up', function (): void {
        $setup = detailScenario();
        $second = detailStore($setup['scenario'], $setup['client'], 'Second storefront', 'detail-2');
        detailWrite($setup, [detailOrder('a', 30_000, '3')]);
        detailWrite($setup, [detailOrder('b', 10_000, '3')], store: $second);

        $stores = detailRender($setup)['store_detail']['stores'];

        expect(array_column($stores, 'name'))->toBe(['Noon Retail storefront', 'Second storefront'])
            ->and(array_column($stores, 'share'))->toEqual([75, 25]);
    });

    it('does not compare stores that sell in different currencies', function (): void {
        $setup = detailScenario();
        $second = detailStore($setup['scenario'], $setup['client'], 'Emirates storefront', 'detail-2', 'AED');
        detailWrite($setup, [detailOrder('a', 30_000, '3')]);
        detailWrite($setup, [detailOrder('b', 10_000, '3', currency: 'AED')], store: $second);

        expect(detailRender($setup)['store_detail']['stores'])->toBe([]);
    });

    it('never reads another client\'s store', function (): void {
        $setup = detailScenario();
        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);
        $foreign = detailStore($setup['scenario'], $other, 'Somebody else', 'detail-x');
        detailWrite($setup, [detailOrder('a', 30_000, '3')]);
        detailWrite($setup, [detailOrder('x', 500_000, '3')], store: $foreign);

        $detail = detailRender($setup)['store_detail'];

        expect($detail['stores'])->toBe([])
            ->and(json_encode($detail))->not->toContain('Somebody else')
            ->and(json_encode($detail))->not->toContain('500000');
    });
});

describe('the products and the channels', function (): void {
    it('ranks products as sold, summed by name and capped', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [
            detailOrder('a', 30_000, '3', items: [detailItem('Abaya', 2, 10_000), detailItem('Scarf', 1, 10_000)]),
            detailOrder('b', 10_000, '2', items: [detailItem('Abaya', 1, 10_000)]),
        ]);

        $products = detailRender($setup)['store_detail']['products'];

        expect($products[0])->toBe(['label' => 'Abaya', 'orders' => 2, 'units' => 3, 'revenue' => 30_000])
            ->and($products[1]['label'])->toBe('Scarf');
    });

    it('keeps what the orders carried apart, with the rest as one unattributed row', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [
            detailOrder('a', 10_000, '3', attribution: OrderAttribution::from(source: 'meta', medium: 'cpc', campaign: 'eid')),
            detailOrder('b', 6_000, '2'),
        ]);

        $detail = detailRender($setup)['store_detail'];
        $unattributed = array_values(array_filter($detail['channels'], static fn (array $row): bool => $row['is_unattributed']));

        expect($unattributed)->toHaveCount(1)
            ->and($unattributed[0]['label'])->toBeNull()
            ->and($unattributed[0]['net_revenue'])->toBe(6_000)
            ->and($detail['attribution_coverage'])->toEqual(62.5);
    });
});

describe('the customers', function (): void {
    it('says how many are new and returning, and nothing that identifies one', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [
            detailOrder('a', 5_000, '60', customer: 'cust-secret-1'),
            detailOrder('b', 5_000, '3', customer: 'cust-secret-1'),
            detailOrder('c', 5_000, '2', customer: 'cust-secret-2'),
        ]);

        $detail = detailRender($setup)['store_detail'];
        $body = (string) json_encode($detail);

        expect($detail['customers'])->toHaveKeys(['new', 'returning', 'unknown', 'new_share', 'unavailable_reason'])
            ->and($body)->not->toContain('cust-secret')
            ->and($body)->not->toContain((string) $setup['store']->getKey())
            ->and($body)->not->toContain((string) $setup['client']->getKey());
    });
});

describe('where it travels', function (): void {
    it('reaches a visitor through a shared link as the same detail', function (): void {
        $setup = detailScenario();
        detailWrite($setup, [detailOrder('a', 5_000, '3', refunded: 500)]);

        $section = detailRender($setup);
        $report = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Shared commerce',
                'client_id' => (string) $setup['client']->getKey(),
                'window' => 'last_30_days',
                'audience' => 'client_safe',
                'sections' => [['kind' => 'store', 'revenue_source' => 'salla']],
            ])->json('data.id');

        $token = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7])->json('meta.token');

        $shared = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data.report.sections.0');

        expect($shared['store_detail']['refunds']['amount'])->toBe($section['store_detail']['refunds']['amount']);
    });
});
