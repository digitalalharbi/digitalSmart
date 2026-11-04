<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceScenario;

/**
 * The reconciliation read: the store as the authority, every other source a claim with its difference — never a sum.
 *
 * @return array{scenario: WorkspaceScenario, client: Client, from: string, until: string}
 */
function revenueReconciliationWorld(?int $events, int $paid, int $tracked = 0, string $currency = 'SAR'): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    Project::factory()->forClient($client)->create();

    /** @var MeasurementSource $source */
    $source = MeasurementSource::query()->create([
        'workspace_id' => $scenario->workspace->getKey(), 'client_id' => $client->getKey(), 'kind' => 'analytics',
        'provider' => Provider::GoogleAnalytics->value, 'external_id' => 'properties/'.random_int(10, 99999), 'name' => 'Web',
    ]);

    if ($events !== null) {
        DB::table('observed_event_counts')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $scenario->workspace->getKey(), 'measurement_source_id' => $source->getKey(),
            'raw_event_name' => 'purchase', 'date' => now()->subDays(3)->format('Y-m-d'), 'event_count' => $events,
            'source' => 'sandbox', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $scenario->workspace->getKey(), 'client_id' => $client->getKey(), 'provider' => 'salla',
        'external_id' => 'rr-'.uniqid(), 'name' => 'Shop', 'currency' => $currency, 'timezone' => 'Asia/Riyadh',
    ]);

    for ($i = 0; $i < $paid; $i++) {
        DB::table('store_orders')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $scenario->workspace->getKey(), 'store_id' => $store->getKey(),
            'external_id' => 'o-'.$i, 'payment_state' => 'paid', 'fulfilment_state' => 'delivered', 'currency' => $currency,
            'subtotal_minor' => 1000, 'total_minor' => 1000, 'refunded_minor' => 0,
            'attribution_evidence' => $i < $tracked ? 'utm_parameters' : 'none',
            'utm_source' => $i < $tracked ? 'snapchat' : null, 'utm_medium' => $i < $tracked ? 'paid' : null, 'utm_campaign' => $i < $tracked ? 'autumn' : null,
            'revenue_source' => 'salla', 'source' => 'synced', 'placed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return [
        'scenario' => $scenario, 'client' => $client,
        'from' => now()->subDays(27)->format('Y-m-d'), 'until' => now()->subDay()->format('Y-m-d'),
    ];
}

/** @param array{client: Client, from: string, until: string} $world */
function revenueReconciliationUrl(array $world): string
{
    return '/api/commerce/reconciliation?'.http_build_query(['client_id' => $world['client']->getKey(), 'from' => $world['from'], 'until' => $world['until']]);
}

/**
 * @param  array{client: Client, from: string, until: string}  $world
 * @return array{data: array<string, mixed>, claims: array<string, array<string, mixed>>}
 */
function revenueReconciliationRead(object $as, array $world): array
{
    /** @var array<string, mixed> $data */
    $data = test()->actingAs($as)->getJson(revenueReconciliationUrl($world))->assertOk()->json('data');
    $claims = [];

    foreach (is_array($data['claims'] ?? null) ? $data['claims'] : [] as $claim) {
        if (is_array($claim) && is_string($claim['source'] ?? null)) {
            $claims[$claim['source']] = $claim;
        }
    }

    return ['data' => $data, 'claims' => $claims];
}

describe('revenue reconciliation', function (): void {
    it('shows the store as the authority and each claim with its difference', function (): void {
        $world = revenueReconciliationWorld(events: 30, paid: 20, tracked: 5);

        ['data' => $data, 'claims' => $claims] = revenueReconciliationRead($world['scenario']->owner, $world);

        expect($data['authority'])->toBe('store')
            ->and($data['store_paid_orders'])->toBe(20)
            ->and($data['store_net_revenue_minor'])->toBe(20000)
            ->and($data['store_currency'])->toBe('SAR')
            ->and($data['stores'])->toHaveCount(1);

        expect($claims['google_analytics']['purchases'])->toBe(30)
            ->and($claims['google_analytics']['purchases_delta'])->toBe(10)
            ->and($claims['google_analytics']['purchases_ratio'])->toEqual(1.5)
            ->and($claims['google_analytics']['metric_source'])->toBe('sandbox')
            // GA4 revenue is not read, so no money and no money difference — never a zero.
            ->and($claims['google_analytics']['revenue_minor'])->toBeNull()
            ->and($claims['google_analytics']['revenue_delta_minor'])->toBeNull()
            ->and($claims['tracking_evidence']['purchases'])->toBe(5)
            ->and($claims['tracking_evidence']['purchases_delta'])->toBe(-15)
            ->and($claims['tracking_evidence']['revenue_minor'])->toBe(5000)
            ->and($claims['tracking_evidence']['revenue_delta_minor'])->toBe(-15000);
    });

    it('never publishes a total across sources', function (): void {
        $world = revenueReconciliationWorld(events: 30, paid: 20);

        ['data' => $data] = revenueReconciliationRead($world['scenario']->owner, $world);

        expect(array_keys($data))->not->toContain('total', 'total_purchases', 'total_revenue_minor', 'combined');
    });

    it('gives a missing source no figure and no difference, with the reason', function (): void {
        $world = revenueReconciliationWorld(events: null, paid: 20);

        ['claims' => $claims] = revenueReconciliationRead($world['scenario']->owner, $world);

        expect($claims['google_analytics']['purchases'])->toBeNull()
            ->and($claims['google_analytics']['purchases_delta'])->toBeNull()
            ->and($claims['google_analytics']['unavailable_reason'])->not->toBeNull()
            ->and($claims['platform_reported']['purchases'])->toBeNull()
            ->and($claims['platform_reported']['revenue_delta_minor'])->toBeNull()
            ->and($claims['platform_reported']['unavailable_reason'])->not->toBeNull();
    });

    it('measures nothing against a store that was never read', function (): void {
        $world = revenueReconciliationWorld(events: 30, paid: 0);

        ['data' => $data, 'claims' => $claims] = revenueReconciliationRead($world['scenario']->owner, $world);

        // Null, not zero orders: thirty events against no authority is not a thirty-order gap.
        expect($data['store_paid_orders'])->toBeNull()
            ->and($claims['google_analytics']['purchases'])->toBe(30)
            ->and($claims['google_analytics']['purchases_delta'])->toBeNull()
            ->and($claims['google_analytics']['purchases_ratio'])->toBeNull();
    });

    it('requires a client and a sane period', function (): void {
        $world = revenueReconciliationWorld(events: 1, paid: 1);
        $owner = $world['scenario']->owner;

        $this->actingAs($owner)->getJson('/api/commerce/reconciliation')->assertStatus(422);
        $this->actingAs($owner)->getJson('/api/commerce/reconciliation?client_id='.$world['client']->getKey().'&from=2026-10-31&until=2026-10-01')
            ->assertStatus(422)->assertJsonPath('code', 'invalid_period');
    });

    it('shows another workspace nothing of this client', function (): void {
        $world = revenueReconciliationWorld(events: 30, paid: 20, tracked: 5);
        $other = WorkspaceScenario::create();

        ['data' => $data, 'claims' => $claims] = revenueReconciliationRead($other->owner, $world);

        expect($data['stores'])->toBe([])
            ->and($data['store_paid_orders'])->toBeNull()
            ->and(array_filter(array_column($claims, 'purchases')))->toBe([]);
    });

    it('is refused without analytics access, before any validation', function (): void {
        $world = revenueReconciliationWorld(events: 1, paid: 1);

        $this->actingAs($world['scenario']->addMember(SystemRole::Creative))->getJson('/api/commerce/reconciliation')->assertForbidden();
    });

    it('needs a session', function (): void {
        $this->getJson('/api/commerce/reconciliation')->assertUnauthorized();
    });
});
