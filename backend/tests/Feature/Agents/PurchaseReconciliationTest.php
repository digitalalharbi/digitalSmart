<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceScenario;

/**
 * GA4 purchase events beside paid store orders: counts and sources only, and a finding only when the gap is wide.
 */
function reconciliationWorld(?int $events, int $orders): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    Project::factory()->forClient($client)->create();

    /** @var MeasurementSource $source */
    $source = MeasurementSource::query()->create([
        'workspace_id' => $scenario->workspace->getKey(), 'client_id' => $client->getKey(), 'kind' => 'analytics',
        'provider' => Provider::GoogleAnalytics->value, 'external_id' => 'properties/1', 'name' => 'Web',
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
        'external_id' => 'rec-'.uniqid(), 'name' => 'Shop', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
    ]);

    for ($i = 0; $i < $orders; $i++) {
        DB::table('store_orders')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $scenario->workspace->getKey(), 'store_id' => $store->getKey(),
            'external_id' => 'o-'.$i, 'payment_state' => 'paid', 'fulfilment_state' => 'delivered', 'currency' => 'SAR',
            'subtotal_minor' => 1000, 'total_minor' => 1000, 'refunded_minor' => 0, 'attribution_evidence' => 'none',
            'revenue_source' => 'salla', 'source' => 'synced', 'placed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['scenario' => $scenario, 'client' => $client];
}

/** @return array<string, mixed> */
function reconciliationCall(object $as, string $clientId): array
{
    $result = test()->actingAs($as)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_purchase_reconciliation', 'arguments' => ['client_id' => $clientId]],
    ])->assertOk()->json('result');

    return ['error' => $result['isError'], 'data' => json_decode((string) $result['content'][0]['text'], true)];
}

it('states both counts and where they came from, and judges nothing', function (): void {
    $w = reconciliationWorld(40, 50);

    $out = reconciliationCall($w['scenario']->owner, (string) $w['client']->getKey());

    expect($out['error'])->toBeFalse()
        ->and($out['data']['ga4_purchase_events'])->toBe(40)
        ->and($out['data']['store_paid_orders'])->toBe(50)
        ->and($out['data']['ga4_source'])->toBe('sandbox')
        ->and($out['data']['stores'][0])->toMatchArray(['authority' => 'salla', 'orders' => 50]);
});

it('reports a property that never sent a purchase event as null, not zero', function (): void {
    $w = reconciliationWorld(null, 30);

    $out = reconciliationCall($w['scenario']->owner, (string) $w['client']->getKey());

    expect($out['data']['ga4_purchase_events'])->toBeNull()->and($out['data']['store_paid_orders'])->toBe(30);
});

it('refuses a reader who may not read analytics', function (): void {
    $w = reconciliationWorld(10, 10);
    $creative = $w['scenario']->addMember(App\Domain\Access\SystemRole::Creative);

    $result = test()->actingAs($creative)->postJson('/api/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'get_purchase_reconciliation', 'arguments' => ['client_id' => (string) $w['client']->getKey()]],
    ])->assertOk()->json('result');

    expect($result['isError'])->toBeTrue();
});

it('opens a watch finding when events and orders are far apart, and says they are different counts', function (): void {
    $w = reconciliationWorld(5, 60);

    test()->artisan('agents:run', ['which' => 'leads', '--workspace' => $w['scenario']->workspace->getKey()])->assertSuccessful();

    $row = DB::table('agent_findings')->where('code', 'PURCHASE_EVENTS_DIVERGE')->where('subject_id', $w['client']->getKey())->whereNull('resolved_at')->sole();
    $evidence = json_decode((string) $row->evidence, true);

    expect($row->severity)->toBe('watch')
        ->and($evidence)->toMatchArray(['ga4_purchase_events' => 5, 'store_paid_orders' => 60, 'ga4_source' => 'sandbox']);
});

it('stays silent when the counts are ordinary, when there are too few orders, or when GA4 sent nothing', function (): void {
    foreach ([[45, 60], [1, 10], [null, 60]] as [$events, $orders]) {
        $w = reconciliationWorld($events, $orders);

        test()->artisan('agents:run', ['which' => 'leads', '--workspace' => $w['scenario']->workspace->getKey()])->assertSuccessful();

        expect(DB::table('agent_findings')->where('code', 'PURCHASE_EVENTS_DIVERGE')->where('subject_id', $w['client']->getKey())->count())->toBe(0);
    }
});
