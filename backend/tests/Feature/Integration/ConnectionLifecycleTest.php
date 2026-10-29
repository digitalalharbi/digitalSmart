<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Connection lifecycle (DS-UC-102 / 103 / 104)
|--------------------------------------------------------------------------
|
| A disconnected connection is never read, and the product says so. Before this, a store read or a GA4 sync after a disconnect
| still called the adapter with emptied credentials, which answered "awaiting provider approval" — a message about the
| provider that sent an operator looking in the wrong place — and stamped the attempt as if something had been tried.
|
| The reconnect route and the disconnect/reconnect audit entries are proven here too: both existed with no test.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, connection: ProviderConnection} */
function lifecycleScenario(Provider $provider): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $connection = lifecycleConnect($scenario, $provider);

    return ['scenario' => $scenario, 'client' => $client, 'connection' => $connection];
}

function lifecycleConnect(WorkspaceScenario $scenario, Provider $provider): ProviderConnection
{
    $values = [];

    foreach ($provider->requiredCredentialKeys() as $key) {
        $values[$key] = Credentials::SANDBOX_TOKEN;
    }

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: $provider,
        owner: ConnectionOwner::agency(),
        name: $provider->label(),
        credentials: new Credentials($values),
    );
    app(ProviderConnectionRepository::class)->save($connection);

    return $connection;
}

describe('a disconnected connection is not read', function (): void {
    it('refuses a store read after the commerce provider was disconnected, without calling the adapter or stamping an attempt', function (): void {
        ['scenario' => $scenario, 'client' => $client] = lifecycleScenario(Provider::Salla);

        $storeId = (string) $this->actingAs($scenario->owner)->postJson('/api/commerce/stores', [
            'provider' => 'salla', 'external_id' => '1183363243', 'client_id' => $client->getKey(),
        ])->assertSuccessful()->json('data.id');

        $this->actingAs($scenario->owner)->deleteJson('/api/integrations/providers/salla')->assertNoContent();

        $read = $this->actingAs($scenario->owner)->postJson("/api/commerce/stores/{$storeId}/read")->assertOk();

        $read->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.api_calls', 0)
            ->assertJsonPath('data.orders_written', 0);
        expect((string) $read->json('data.failure_message'))->toContain('disconnected')
            ->and((string) $read->json('data.failure_message'))->not->toContain('approval')
            // Nothing was tried, so nothing is stamped as tried.
            ->and(DB::table('stores')->where('id', $storeId)->value('last_synced_at'))->toBeNull()
            ->and(DB::table('store_orders')->where('store_id', $storeId)->count())->toBe(0);
    });

    it('refuses a GA4 sync after the analytics provider was disconnected, and keeps the days already read', function (): void {
        ['scenario' => $scenario, 'client' => $client] = lifecycleScenario(Provider::GoogleAnalytics);

        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->create([
            'workspace_id' => $scenario->workspace->getKey(), 'client_id' => $client->getKey(), 'kind' => 'analytics',
            'provider' => Provider::GoogleAnalytics->value, 'external_id' => 'properties/412006881', 'name' => 'Web',
        ]);

        $this->actingAs($scenario->owner)->postJson("/api/measurement/sources/{$source->getKey()}/sync")->assertOk()
            ->assertJsonPath('data.status', 'succeeded');
        $before = DB::table('observed_event_counts')->where('measurement_source_id', $source->getKey())->count();
        $lastSynced = DB::table('measurement_sources')->where('id', $source->getKey())->value('last_synced_at');
        expect($before)->toBeGreaterThan(0);

        $this->actingAs($scenario->owner)->deleteJson('/api/integrations/providers/google_analytics')->assertNoContent();

        $sync = $this->actingAs($scenario->owner)->postJson("/api/measurement/sources/{$source->getKey()}/sync")->assertOk();

        $sync->assertJsonPath('data.status', 'failed')->assertJsonPath('data.api_calls', 0);
        expect((string) $sync->json('data.failure_message'))->toContain('disconnected')
            ->and(DB::table('observed_event_counts')->where('measurement_source_id', $source->getKey())->count())->toBe($before)
            ->and(DB::table('measurement_sources')->where('id', $source->getKey())->value('last_synced_at'))->toBe($lastSynced);
    });

    it('reads again once the credentials are put back', function (): void {
        ['scenario' => $scenario, 'client' => $client] = lifecycleScenario(Provider::Salla);
        $storeId = (string) $this->actingAs($scenario->owner)->postJson('/api/commerce/stores', [
            'provider' => 'salla', 'external_id' => '1183363243', 'client_id' => $client->getKey(),
        ])->json('data.id');

        $this->actingAs($scenario->owner)->deleteJson('/api/integrations/providers/salla')->assertNoContent();
        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/salla', ['credentials' => [
            'client_id' => Credentials::SANDBOX_TOKEN, 'client_secret' => Credentials::SANDBOX_TOKEN,
        ]])->assertSuccessful();

        $this->actingAs($scenario->owner)->postJson("/api/commerce/stores/{$storeId}/read")->assertOk()->assertJsonPath('data.status', 'succeeded');
    });
});

describe('replacing the credentials of one connection', function (): void {
    it('replaces them, returns key names only and records who did it', function (): void {
        ['scenario' => $scenario, 'connection' => $connection] = lifecycleScenario(Provider::Salla);

        $response = $this->actingAs($scenario->owner)->putJson("/api/integrations/connections/{$connection->id->value}/credentials", [
            'credentials' => ['client_id' => 'new-client-id-value', 'client_secret' => 'new-client-secret-value'],
        ])->assertOk();

        $body = (string) json_encode($response->json());
        expect($body)->not->toContain('new-client-id-value')
            ->and($body)->not->toContain('new-client-secret-value');

        $entry = AuditLog::query()->where('action', AuditAction::IntegrationConnectionReconnected->value)->firstOrFail();
        expect($entry->getAttribute('actor_id'))->toBe($scenario->owner->getKey())
            ->and($entry->getAttribute('auditable_id'))->toBe($connection->id->value)
            ->and(json_encode($entry->getAttribute('changes')))->not->toContain('new-client-secret-value');
    });

    it('is not a media buyer’s to do', function (): void {
        ['scenario' => $scenario, 'connection' => $connection] = lifecycleScenario(Provider::Salla);
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $this->actingAs($buyer)->putJson("/api/integrations/connections/{$connection->id->value}/credentials", [
            'credentials' => ['client_id' => 'a', 'client_secret' => 'b'],
        ])->assertForbidden();
    });

    it('cannot reach another workspace’s connection', function (): void {
        ['connection' => $theirs] = lifecycleScenario(Provider::Salla);
        $mine = WorkspaceScenario::create();

        $this->actingAs($mine->owner)->putJson("/api/integrations/connections/{$theirs->id->value}/credentials", [
            'credentials' => ['client_id' => 'a', 'client_secret' => 'b'],
        ])->assertNotFound();
        $this->actingAs($mine->owner)->deleteJson("/api/integrations/connections/{$theirs->id->value}")->assertNotFound();
    });
});

it('records who disconnected a provider, and never a credential', function (): void {
    ['scenario' => $scenario] = lifecycleScenario(Provider::Salla);

    $this->actingAs($scenario->owner)->deleteJson('/api/integrations/providers/salla')->assertNoContent();

    $entry = AuditLog::query()->where('action', AuditAction::IntegrationDisconnected->value)->firstOrFail();
    expect($entry->getAttribute('actor_id'))->toBe($scenario->owner->getKey())
        ->and((string) $entry->getAttribute('summary'))->toContain('destroyed its stored credentials')
        ->and(json_encode($entry->getAttribute('changes')))->not->toContain(Credentials::SANDBOX_TOKEN);
});
