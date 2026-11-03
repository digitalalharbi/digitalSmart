<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\DTO\OrderRecord;
use App\Application\Commerce\DTO\RefundRecord;
use App\Application\Commerce\DTO\ShipmentRecord;
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
| Stored instants are UTC (DS-UC-202, groundwork)
|--------------------------------------------------------------------------
|
| Salla's mapper keeps the zone a payload carried (`Asia/Riyadh`) on the DateTimeImmutable — correctly, an instant has a zone. The
| writer then bound that object straight into a `timestamp without time zone` column, and Laravel formats a DateTimeInterface in its
| own zone without converting it: an order placed at 01:30 in Riyadh was stored as `01:30`, a wall-clock time every query reads as UTC.
| Salla and Zid rows therefore meant different things in the same column, and no day boundary could be cut in a store's own time zone
| with any confidence. Every instant is now converted to UTC where it is written.
|
*/

/** @return array{scenario: WorkspaceScenario, store: Store} */
function instantsScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'instants-1',
        'name' => 'Instants',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'store' => $store];
}

/**
 * @param  array{scenario: WorkspaceScenario, store: Store}  $setup
 * @param  list<OrderRecord>  $orders
 */
function storeInstants(array $setup, array $orders): void
{
    app(CommerceStore::class)->upsertOrders(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['store']->getKey(),
        $orders,
        RevenueSource::Salla,
        MetricSource::Synced,
    );
}

function instantOrder(string $id, string $placedAt, ?string $paidAt = null, ?string $cancelledAt = null, ?string $updatedAt = null): OrderRecord
{
    $at = static fn (?string $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable($v);

    return new OrderRecord(
        externalId: $id,
        paymentState: PaymentState::Paid,
        fulfilmentState: FulfilmentState::Delivered,
        currency: 'SAR',
        totalMinor: 10_000,
        placedAt: new DateTimeImmutable($placedAt),
        attribution: OrderAttribution::none(),
        paidAt: $at($paidAt),
        cancelledAt: $at($cancelledAt),
        providerUpdatedAt: $at($updatedAt),
    );
}

describe('instants a provider reports in a local zone', function (): void {
    it('are stored as the UTC instant they are, not as their wall-clock time', function (): void {
        $setup = instantsScenario();

        // 01:30 on 2 October in Riyadh (UTC+3) is 22:30 on 1 October UTC.
        storeInstants($setup, [instantOrder('riyadh', '2026-10-02 01:30:00+03:00')]);

        expect(DB::table('store_orders')->where('external_id', 'riyadh')->value('placed_at'))->toBe('2026-10-01 22:30:00');
    });

    it('are converted in every column that carries a provider time', function (): void {
        $setup = instantsScenario();
        storeInstants($setup, [instantOrder(
            'all',
            placedAt: '2026-10-02 01:30:00+03:00',
            paidAt: '2026-10-02 01:45:00+03:00',
            cancelledAt: '2026-10-02 09:00:00+03:00',
            updatedAt: '2026-10-02 12:15:00+03:00',
        )]);

        $row = DB::table('store_orders')->where('external_id', 'all')->first();

        expect($row)->not->toBeNull();
        assert($row instanceof stdClass);
        expect($row->placed_at)->toBe('2026-10-01 22:30:00')
            ->and($row->paid_at)->toBe('2026-10-01 22:45:00')
            ->and($row->cancelled_at)->toBe('2026-10-02 06:00:00')
            ->and($row->provider_updated_at)->toBe('2026-10-02 09:15:00');
    });

    it('are converted on refunds and shipments too', function (): void {
        $setup = instantsScenario();
        $order = instantOrder('with-parts', '2026-10-02 01:30:00+03:00');
        $order = new OrderRecord(
            externalId: $order->externalId,
            paymentState: $order->paymentState,
            fulfilmentState: $order->fulfilmentState,
            currency: $order->currency,
            totalMinor: $order->totalMinor,
            placedAt: $order->placedAt,
            attribution: $order->attribution,
            refundedMinor: 2_000,
            refunds: [new RefundRecord(2_000, 'SAR', new DateTimeImmutable('2026-10-03 00:30:00+03:00'))],
            shipments: [new ShipmentRecord(shippedAt: new DateTimeImmutable('2026-10-03 02:00:00+03:00'), deliveredAt: new DateTimeImmutable('2026-10-04 23:30:00+03:00'))],
        );
        storeInstants($setup, [$order]);

        expect(DB::table('store_refunds')->value('refunded_at'))->toBe('2026-10-02 21:30:00')
            ->and(DB::table('store_shipments')->value('shipped_at'))->toBe('2026-10-02 23:00:00')
            ->and(DB::table('store_shipments')->value('delivered_at'))->toBe('2026-10-04 20:30:00');
    });

    it('leave an instant already in UTC exactly as it was, and keep an absent one absent', function (): void {
        $setup = instantsScenario();
        storeInstants($setup, [instantOrder('utc', '2026-10-02 10:00:00+00:00')]);

        $row = DB::table('store_orders')->where('external_id', 'utc')->first();
        expect($row)->not->toBeNull();
        assert($row instanceof stdClass);
        expect($row->placed_at)->toBe('2026-10-02 10:00:00')
            ->and($row->paid_at)->toBeNull()
            ->and($row->cancelled_at)->toBeNull();
    });

    it('make the same order the same row whichever zone it is reported in', function (): void {
        $setup = instantsScenario();

        // The same instant, once as Riyadh wall-clock and once as UTC: a re-read must not move it.
        storeInstants($setup, [instantOrder('same', '2026-10-02 01:30:00+03:00')]);
        storeInstants($setup, [instantOrder('same', '2026-10-01 22:30:00+00:00')]);

        expect(DB::table('store_orders')->where('external_id', 'same')->count())->toBe(1)
            ->and(DB::table('store_orders')->where('external_id', 'same')->value('placed_at'))->toBe('2026-10-01 22:30:00');
    });
});
