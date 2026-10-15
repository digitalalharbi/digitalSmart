<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/**
 * @param  list<array{int, int, string}>  $orders  total, refunded, attribution evidence
 */
function rollupStore(WorkspaceScenario $s, Client $client, string $name, string $currency, array $orders): Store
{
    /** @var Store $store */
    $store = Store::query()->create([
        'workspace_id' => $s->workspace->getKey(), 'client_id' => $client->getKey(), 'provider' => 'salla',
        'external_id' => 'rollup-'.uniqid(), 'name' => $name, 'currency' => $currency, 'timezone' => 'Asia/Riyadh',
    ]);

    foreach ($orders as $i => [$total, $refunded, $evidence]) {
        DB::table('store_orders')->insert([
            'id' => (string) Illuminate\Support\Str::uuid(), 'workspace_id' => $s->workspace->getKey(), 'store_id' => $store->getKey(),
            'external_id' => 'o-'.$i.uniqid(), 'payment_state' => 'paid', 'fulfilment_state' => 'delivered',
            'currency' => $currency, 'subtotal_minor' => $total, 'total_minor' => $total, 'refunded_minor' => $refunded,
            'attribution_evidence' => $evidence, 'referrer' => $evidence === 'referrer' ? 'https://instagram.com' : null, 'revenue_source' => 'salla', 'source' => 'synced',
            'placed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $store;
}

it('lists every store with its own currency and never totals them', function (): void {
    $s = WorkspaceScenario::create();
    $a = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    $b = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    rollupStore($s, $a, 'Alpha', 'SAR', [[10000, 1000, 'referrer'], [20000, 0, 'none']]);
    rollupStore($s, $b, 'Beta', 'USD', [[5000, 0, 'none']]);
    rollupStore($s, $b, 'Quiet', 'USD', []);

    $rows = $this->actingAs($s->owner)->getJson('/api/commerce/rollup?revenue_source=salla')->assertOk()->json('data.stores');
    $by = array_column($rows, null, 'store_name');

    expect($rows)->toHaveCount(3)
        ->and($by['Alpha']['orders'])->toBe(2)
        ->and($by['Alpha']['revenue_minor'])->toBe(30000)
        ->and($by['Alpha']['net_revenue_minor'])->toBe(29000)
        ->and($by['Alpha']['average_order_minor'])->toBe(15000)
        ->and($by['Alpha']['attributed_revenue_minor'])->toBe(9000)
        ->and($by['Alpha']['currency'])->toBe('SAR')
        ->and($by['Beta']['currency'])->toBe('USD')
        // A store that sold nothing is listed, and its average is unknown rather than zero.
        ->and($by['Quiet']['orders'])->toBe(0)
        ->and($by['Quiet']['average_order_minor'])->toBeNull();
});

it('requires a revenue source', function (): void {
    $s = WorkspaceScenario::create();

    $this->actingAs($s->owner)->getJson('/api/commerce/rollup')->assertStatus(422)->assertJsonPath('code', 'revenue_source_required');
});

it('shows a client viewer only their own client’s stores', function (): void {
    $s = WorkspaceScenario::create();
    $mine = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    $theirs = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    $project = Project::factory()->forClient($mine)->create();
    rollupStore($s, $mine, 'Mine', 'SAR', [[100, 0, 'none']]);
    rollupStore($s, $theirs, 'Theirs', 'SAR', [[999, 0, 'none']]);

    $viewer = $s->addMember(SystemRole::ClientViewer);
    ProjectMember::query()->create(['project_id' => $project->getKey(), 'user_id' => $viewer->getKey(), 'role_id' => $s->role(SystemRole::ClientViewer)->getKey()]);

    $response = $this->actingAs($viewer)->getJson('/api/commerce/rollup?revenue_source=salla');

    if ($response->status() === 200) {
        $names = array_column($response->json('data.stores'), 'store_name');
        expect($names)->toBe(['Mine']);
    } else {
        $response->assertForbidden();
    }
});

it('reads six stores in as many statements as one', function (): void {
    $s = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $s->workspace->getKey()]);
    rollupStore($s, $client, 'One', 'SAR', [[100, 0, 'none']]);

    $count = function () use ($s): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($s->owner)->getJson('/api/commerce/rollup?revenue_source=salla')->assertOk();
        $n = count(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains((string) $q['query'], 'store')));
        DB::disableQueryLog();

        return $n;
    };

    $one = $count();

    foreach (range(1, 5) as $i) {
        rollupStore($s, $client, 'Store '.$i, 'SAR', [[100, 0, 'none'], [200, 0, 'none']]);
    }

    expect($count())->toBe($one);
});
