<?php

declare(strict_types=1);

use App\Application\Commerce\Contract\CommerceStore;
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
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Store figures in a report
|--------------------------------------------------------------------------
|
| A store's revenue is a claim by a named authority, so a store section names one and reads only that authority's
| orders. What is asserted here is the separation — one authority, one client, net of refunds, pending orders beside
| the total and never inside it — and that a modelled figure cannot reach a report written for a client.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, store: Store} */
function storeReportScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'client_id' => $client->getKey(),
        'provider' => Provider::Salla->value,
        'external_id' => 'store-report-1',
        'name' => 'Noon Retail storefront',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'store' => $store];
}

function storeReportOrder(string $id, int $total, PaymentState $payment = PaymentState::Paid, int $refunded = 0): OrderRecord
{
    $placedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days');

    return new OrderRecord(
        externalId: $id,
        paymentState: $payment,
        fulfilmentState: FulfilmentState::Delivered,
        currency: 'SAR',
        totalMinor: $total,
        placedAt: $placedAt,
        attribution: OrderAttribution::none(),
        refundedMinor: $refunded,
        customerExternalId: $id,
        items: [],
        refunds: $refunded > 0 ? [new RefundRecord($refunded, 'SAR', $placedAt)] : [],
    );
}

/** @param array{scenario: WorkspaceScenario, client: Client, store: Store} $setup */
function storeReportOrders(array $setup, RevenueSource $authority = RevenueSource::Salla): void
{
    app(CommerceStore::class)->upsertOrders(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['store']->getKey(),
        [
            storeReportOrder('o1', 10_000),
            storeReportOrder('o2', 5_000, refunded: 1_000),
            storeReportOrder('o3', 3_000, PaymentState::Pending),
        ],
        $authority,
        MetricSource::Synced,
    );
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, store: Store}  $setup
 * @param  array<string, mixed>  $overrides
 */
function storeReport(array $setup, array $overrides = []): string
{
    return (string) test()->actingAs($setup['scenario']->owner)
        ->postJson('/api/reports', [
            'name' => 'Store month',
            'client_id' => (string) $setup['client']->getKey(),
            'window' => 'last_30_days',
            'sections' => [['kind' => 'store', 'revenue_source' => 'salla']],
            ...$overrides,
        ])
        ->assertCreated()
        ->json('data.id');
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, store: Store}  $setup
 * @return array<string, mixed>
 */
function renderStoreReport(array $setup, string $report): array
{
    /** @var array<string, mixed> $section */
    $section = test()->actingAs($setup['scenario']->owner)
        ->getJson("/api/reports/{$report}")
        ->assertOk()
        ->json('data.sections.0');

    return $section;
}

/** @param list<array{key: string, value: int|float|null}> $store */
function storeFigure(array $store, string $key): int|float|null
{
    foreach ($store as $figure) {
        if ($figure['key'] === $key) {
            return $figure['value'];
        }
    }

    return null;
}

describe('what a store section says', function (): void {
    it('reads the named authority, net of refunds, with unpaid orders beside the total', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup);

        $section = renderStoreReport($setup, storeReport($setup));

        expect($section['kind'])->toBe('store')
            ->and($section['revenue_source'])->toBe('salla')
            ->and($section['revenue_source_label'])->toBe('Salla')
            ->and($section['is_empty'])->toBeFalse()
            ->and($section['currency'])->toBe('SAR');

        expect(storeFigure($section['store'], 'net_revenue'))->toBe(14_000)
            ->and(storeFigure($section['store'], 'orders'))->toBe(2)
            ->and(storeFigure($section['store'], 'aov'))->toBe(7_000)
            ->and(storeFigure($section['store'], 'refunded'))->toBe(1_000)
            ->and(storeFigure($section['store'], 'pending_orders'))->toBe(1);

        expect(implode(' ', $section['caveats']))->toContain('not yet paid');
    });

    it('does not let one authority speak for another', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup, RevenueSource::Salla);

        $section = renderStoreReport($setup, storeReport($setup, [
            'sections' => [['kind' => 'store', 'revenue_source' => 'google_analytics']],
        ]));

        expect($section['is_empty'])->toBeTrue()
            ->and(storeFigure($section['store'], 'net_revenue'))->toBe(0);
    });

    it('has no average order value when nothing sold, rather than an average of nothing', function (): void {
        $setup = storeReportScenario();

        $section = renderStoreReport($setup, storeReport($setup));

        expect($section['is_empty'])->toBeTrue()
            ->and(storeFigure($section['store'], 'aov'))->toBeNull();
    });

    it('reads only the report\'s own client', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup);
        $other = Client::factory()->create(['workspace_id' => $setup['scenario']->workspace->getKey()]);

        $section = renderStoreReport($setup, storeReport($setup, ['client_id' => (string) $other->getKey()]));

        expect($section['is_empty'])->toBeTrue();
    });

    it('survives a round trip through the saved definition', function (): void {
        $setup = storeReportScenario();
        $report = storeReport($setup);

        $definition = test()->actingAs($setup['scenario']->owner)
            ->getJson("/api/reports/{$report}/definition")
            ->assertOk()
            ->json('data.sections.0');

        expect($definition['kind'])->toBe('store')
            ->and($definition['revenue_source'])->toBe('salla')
            ->and($definition['metrics'])->toBe([]);
    });
});

describe('what a store section refuses', function (): void {
    it('needs to say whose revenue it is', function (): void {
        $setup = storeReportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Unnamed',
                'client_id' => (string) $setup['client']->getKey(),
                'sections' => [['kind' => 'store']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'revenue_source_required');
    });

    it('needs a client: an agency-wide report has no single store to read', function (): void {
        $setup = storeReportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Everyone',
                'sections' => [['kind' => 'store', 'revenue_source' => 'salla']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_needs_client');
    });

    it('refuses a source the product does not know', function (): void {
        $setup = storeReportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Odd',
                'client_id' => (string) $setup['client']->getKey(),
                'sections' => [['kind' => 'store', 'revenue_source' => 'the_vibes']],
            ])
            ->assertStatus(422);
    });

    it('keeps a modelled figure out of a report written for a client', function (): void {
        $setup = storeReportScenario();

        test()->actingAs($setup['scenario']->owner)
            ->postJson('/api/reports', [
                'name' => 'Attributed for the client',
                'client_id' => (string) $setup['client']->getKey(),
                'audience' => 'client_safe',
                'sections' => [['kind' => 'store', 'revenue_source' => 'attributed']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'modelled_not_client_safe');
    });

    it('allows the modelled figure internally, and says it must not be shown as actual', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup, RevenueSource::Attributed);

        $section = renderStoreReport($setup, storeReport($setup, [
            'sections' => [['kind' => 'store', 'revenue_source' => 'attributed']],
        ]));

        expect(implode(' ', $section['caveats']))->toContain('must not be presented to a client as actual');
    });

    it('is held by the schema too: a store section cannot exist without its source', function (): void {
        $setup = storeReportScenario();
        $report = storeReport($setup);

        expect(fn () => DB::table('report_sections')->where('report_id', $report)->update(['revenue_source' => null]))
            ->toThrow(Illuminate\Database\QueryException::class);
    });
});

describe('where it travels', function (): void {
    it('reaches a client through a shared link, as the same figures', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup);
        $report = storeReport($setup, ['audience' => 'client_safe']);

        $token = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7])
            ->assertCreated()
            ->json('meta.token');

        $shared = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data.report.sections.0');

        expect($shared['kind'])->toBe('store')
            ->and(storeFigure($shared['store'], 'net_revenue'))->toBe(14_000);
    });

    it('is frozen with the rest of a snapshot', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup);
        $report = storeReport($setup, ['audience' => 'client_safe']);

        $snapshot = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/snapshots", ['label' => 'September'])
            ->assertCreated()
            ->json('data.id');

        app(CommerceStore::class)->upsertOrders(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['store']->getKey(),
            [storeReportOrder('o9', 90_000)],
            RevenueSource::Salla,
            MetricSource::Synced,
        );

        $token = (string) test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/reports/{$report}/shares", ['days' => 7, 'snapshot_id' => $snapshot])
            ->assertCreated()
            ->json('meta.token');

        $shared = test()->getJson("/api/shared-reports/{$token}")->assertOk()->json('data.report.sections.0');

        expect(storeFigure($shared['store'], 'net_revenue'))->toBe(14_000);
    });

    it('is written into the CSV in major units, with the authority named', function (): void {
        $setup = storeReportScenario();
        storeReportOrders($setup);
        $report = storeReport($setup);

        $csv = test()->actingAs($setup['scenario']->owner)
            ->get("/api/reports/{$report}/csv")
            ->assertOk()
            ->getContent();

        expect($csv)->toContain('"Revenue according to","Salla"')
            ->and($csv)->toContain('"Net revenue (SAR)","140.00"');
    });
});
