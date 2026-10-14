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
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Connecting a store to a client
|--------------------------------------------------------------------------
|
| The half of the commerce module that was missing. A workspace could connect Salla, the webhook endpoint
| could authenticate its deliveries, the sync could read its orders and the analytics could report them —
| and nothing could say *which client a merchant belongs to*, so every delivery was rejected as
| `unknown_store` and every figure was empty. A `stores` row could only be written by a seeder.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client} */
function storeConnectionScenario(bool $connected = true): array
{
    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    if ($connected) {
        /* Sandbox credentials: the adapter then reads response-shaped fixtures. No path invents an order. */
        app(ProviderConnectionRepository::class)->save(ProviderConnection::register(
            id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
            workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
            provider: Provider::Salla,
            owner: ConnectionOwner::agency(),
            name: 'Salla',
            credentials: new Credentials([
                'client_id' => Credentials::SANDBOX_TOKEN,
                'client_secret' => Credentials::SANDBOX_TOKEN,
            ]),
        ));
    }

    return ['scenario' => $scenario, 'client' => $client];
}

it('requires authentication', function (): void {
    $this->getJson('/api/commerce/stores')->assertUnauthorized();
});

describe('what the connection can read', function (): void {
    it('lists the merchants a commerce connection can see', function (): void {
        ['scenario' => $scenario] = storeConnectionScenario();

        $this->actingAs($scenario->owner)
            ->getJson('/api/commerce/stores/connectable')
            ->assertOk()
            ->assertJsonPath('data.0.provider', 'salla')
            ->assertJsonPath('data.0.external_id', '1183363243')
            ->assertJsonPath('data.0.currency', 'SAR')
            /* Not registered yet, and the list says so rather than leaving it to be inferred. */
            ->assertJsonPath('data.0.is_registered', false)
            ->assertJsonPath('data.0.registered_to_client_name', null);
    });

    /*
     * Nothing connected is the ordinary state of a checkout, and the integrations page is where it is fixed.
     * An empty list is the honest answer; a failure would be one the reader cannot act on from here.
     */
    it('answers with nothing, not an error, when no platform is connected', function (): void {
        ['scenario' => $scenario] = storeConnectionScenario(connected: false);

        $this->actingAs($scenario->owner)
            ->getJson('/api/commerce/stores/connectable')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    });

    it('is not a media buyer’s to read', function (): void {
        ['scenario' => $scenario] = storeConnectionScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $this->actingAs($buyer)->getJson('/api/commerce/stores/connectable')->assertForbidden();
    });
});

describe('connecting one', function (): void {
    it('registers the merchant against a client, with the platform’s own details', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $response = $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->assertCreated();

        /*
         * The name, currency, domain and timezone are read back from the platform rather than taken from the
         * request. A typed currency would be the most expensive field here to get wrong: every amount from this
         * store is stored in it, and nothing downstream could detect the mistake.
         */
        $response->assertJsonPath('data.name', 'SANDBOX — Noon Retail')
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.domain', 'noonretail.sa')
            ->assertJsonPath('data.client_name', $client->getAttribute('name'))
            /* Never read yet, and it says so rather than claiming a timestamp it does not have. */
            ->assertJsonPath('data.last_synced_at', null)
            ->assertJsonPath('data.orders_synced_through', null)
            ->assertJsonPath('data.is_readable', true);

        expect(DB::table('stores')->where('external_id', '1183363243')->count())->toBe(1);
    });

    it('records who decided whose revenue those orders are', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->assertCreated();

        $entry = AuditLog::query()->where('action', AuditAction::StoreConnected->value)->sole();

        expect($entry->getAttribute('changes')['currency'])->toBe('SAR')
            ->and($entry->getAttribute('changes')['client_id'])->toBe((string) $client->getKey())
            ->and($entry->getAttribute('actor_id'))->toBe((string) $scenario->owner->getKey());
    });

    /*
     * One store belongs to one client. Registering the same merchant twice would count every order twice, and
     * the refusal names the client that already has it — which is the only part a person can act on.
     */
    it('refuses a merchant another client already reads, and names them', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();
        $other = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)->postJson('/api/commerce/stores', [
            'provider' => 'salla',
            'external_id' => '1183363243',
            'client_id' => $client->getKey(),
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $other->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_connected')
            ->assertJsonFragment(['message' => sprintf(
                '“1183363243” is already read for %s. One store belongs to one client, or every order would count twice.',
                $client->getAttribute('name'),
            )]);
    });

    it('refuses a merchant the connection cannot see', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '404404404',
                'client_id' => $client->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_connected');
    });

    it('refuses a platform that is not connected yet, and says where to connect it', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario(connected: false);

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_connected')
            ->assertJsonFragment(['message' => 'Salla is not connected to this workspace yet. Connect it on the integrations page first.']);
    });

    /* An analytics property is not a store, and the refusal says which kind of platform this needs. */
    it('refuses a provider that is not a commerce platform', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'google_analytics',
                'external_id' => 'properties/1',
                'client_id' => $client->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_connected');
    });

    /* Connecting a platform's data to a client is workspace setup, not project work. */
    it('is not a project manager’s decision to make', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($manager)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->assertForbidden();
    });

    /*
     * A client the actor cannot see is a `404`, not a `422`. Deciding whose revenue a merchant's orders become
     * is not a decision to be made about a client the decider cannot otherwise reach — and confirming that the
     * client exists somewhere else would itself cross a tenant boundary.
     */
    it('reports a client in another workspace as not found', function (): void {
        ['scenario' => $scenario] = storeConnectionScenario();
        $stranger = WorkspaceScenario::create('Theirs');
        $theirClient = Client::factory()->create(['workspace_id' => $stranger->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $theirClient->getKey(),
            ])
            ->assertNotFound();

        expect(DB::table('stores')->count())->toBe(0);
    });
});

describe('reading one', function (): void {
    it('reads the store on request and reports what came back', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $storeId = $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->json('data.id');

        $response = $this->actingAs($scenario->owner)
            ->postJson("/api/commerce/stores/{$storeId}/read")
            ->assertOk();

        /* Sandbox, and labelled as such: fixtures are never reported as a live read. */
        $response->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.source', 'sandbox');

        expect($response->json('data.orders_written'))->toBeGreaterThan(0);

        /* And the list now says when it was read, which is what the page beside the figures shows. */
        $listed = $this->actingAs($scenario->owner)->getJson('/api/commerce/stores')->assertOk();

        expect($listed->json('data.0.last_synced_at'))->not->toBeNull()
            ->and($listed->json('data.0.orders_synced_through'))->not->toBeNull();
    });

    it('records the read, with its honesty label', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $storeId = $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->json('data.id');

        $this->actingAs($scenario->owner)->postJson("/api/commerce/stores/{$storeId}/read")->assertOk();

        $entry = AuditLog::query()->where('action', AuditAction::StoreRead->value)->sole();

        expect($entry->getAttribute('changes')['source'])->toBe('sandbox')
            ->and($entry->getAttribute('changes')['status'])->toBe('succeeded');
    });

    it('is not a media buyer’s to trigger', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);

        $storeId = $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->json('data.id');

        $this->actingAs($buyer)->postJson("/api/commerce/stores/{$storeId}/read")->assertForbidden();
    });

    it('reports a store in another workspace as not found', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();
        $storeId = $this->actingAs($scenario->owner)
            ->postJson('/api/commerce/stores', [
                'provider' => 'salla',
                'external_id' => '1183363243',
                'client_id' => $client->getKey(),
            ])
            ->json('data.id');

        $stranger = WorkspaceScenario::create('Theirs');

        $this->actingAs($stranger->owner)
            ->postJson("/api/commerce/stores/{$storeId}/read")
            ->assertNotFound();
    });
});

describe('the scheduled pass', function (): void {
    /*
     * The reconciliation command, which is why `SyncStore` exists: webhooks are faster and not sufficient.
     * It queues rather than reading inline, so one slow platform cannot stall the others.
     */
    it('queues a read for every connected store', function (): void {
        ['scenario' => $scenario, 'client' => $client] = storeConnectionScenario();

        $this->actingAs($scenario->owner)->postJson('/api/commerce/stores', [
            'provider' => 'salla',
            'external_id' => '1183363243',
            'client_id' => $client->getKey(),
        ])->assertCreated();

        Illuminate\Support\Facades\Queue::fake();

        $this->artisan('commerce:sync')->assertExitCode(0);

        Illuminate\Support\Facades\Queue::assertPushed(App\Interface\Queue\ReadStoreJob::class, 1);
    });

    it('says plainly when there is nothing to read', function (): void {
        storeConnectionScenario(connected: false);

        Illuminate\Support\Facades\Queue::fake();

        $this->artisan('commerce:sync')->expectsOutputToContain('No connected store can be read.')->assertExitCode(0);

        Illuminate\Support\Facades\Queue::assertNothingPushed();
    });
});
