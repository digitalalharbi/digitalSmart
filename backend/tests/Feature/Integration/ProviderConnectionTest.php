<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Integration\Provider;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\ProviderConnection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

const SANDBOX = AbstractProviderAdapter::SANDBOX_TOKEN;

it('requires authentication', function (): void {
    $this->getJson('/api/integrations/providers')->assertUnauthorized();
});

describe('listing providers', function (): void {
    it('lists every provider, connected or not', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers');

        $response->assertOk()->assertJsonCount(count(Provider::cases()), 'data');
        expect(array_column($response->json('data'), 'provider'))
            ->toContain('meta', 'google_ads', 'tiktok', 'snapchat', 'x', 'linkedin', 'openai');
    });

    it('reports an unconfigured provider as blocked, naming the missing credentials', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers');

        $google = jsonRow($response->json('data'), 'provider', 'google_ads');

        expect($google['status'])->toBe('not_configured')
            ->and($google['blocked_external'])->toBeTrue()
            // The exact thing a human must go and obtain.
            ->and($google['missing_credentials'])->toBe(['client_id', 'client_secret', 'developer_token']);
    });

    it('never claims capabilities for a platform with no published contract', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers');
        $openai = jsonRow($response->json('data'), 'provider', 'openai');

        expect($openai['capabilities'])->toBe([])
            ->and($openai['blocked_external'])->toBeTrue();
    });

    it('marks write capabilities so the interface can gate them', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers');
        $meta = jsonRow($response->json('data'), 'provider', 'meta');

        $capabilities = is_array($meta['capabilities']) ? $meta['capabilities'] : [];
        $writes = [];

        foreach ($capabilities as $capability) {
            if (is_array($capability) && ($capability['is_write'] ?? false) === true) {
                $writes[] = $capability['slug'];
            }
        }

        expect($writes)->toContain('budget_mutation', 'campaign_status_mutation');
    });

    it('hides integrations from a role without workspace access', function (): void {
        $scenario = workspaceScenario();
        $viewer = $scenario->addMember(SystemRole::ClientViewer);

        $this->actingAs($viewer)->getJson('/api/integrations/providers')->assertForbidden();
    });
});

describe('configuring a connection', function (): void {
    /*
     * The integrations page is where somebody decides a provider is ready, and it could not tell a connection
     * holding this repository's fixtures from one holding a client's keys: both read "Connected". Every figure
     * carries `SANDBOX`, so no report was ever dishonest — but the decision was being made on a page that said
     * nothing, which is precisely the mistake live commissioning exists to prevent.
     */
    it('says when a connection is holding this repository’s fixtures', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)
            ->putJson('/api/integrations/providers/meta', [
                'credentials' => ['client_id' => 'sandbox', 'client_secret' => 'sandbox'],
            ])
            ->assertCreated();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers')->json('data');
        $meta = Arr::first($rows, static fn (array $row): bool => $row['provider'] === 'meta');

        expect($meta['is_sandbox'] ?? null)->toBeTrue();
    });

    it('says when a connection is holding real credentials', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)
            ->putJson('/api/integrations/providers/meta', [
                'credentials' => ['client_id' => 'a-real-looking-id', 'client_secret' => 'a-real-looking-secret'],
            ])
            ->assertCreated();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers')->json('data');
        $meta = Arr::first($rows, static fn (array $row): bool => $row['provider'] === 'meta');

        /* True of the credential, not of the data: nothing has been read yet, and the figures say so. */
        expect($meta['is_sandbox'] ?? null)->toBeFalse();
    });

    it('stores credentials and never returns their values', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'real-id', 'client_secret' => 'real-secret'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'configured')
            ->assertJsonPath('data.credential_keys', ['client_id', 'client_secret']);

        expect($response->content())->not->toContain('real-secret');
    });

    it('encrypts the credentials at rest', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'real-id', 'client_secret' => 'plaintext-secret'],
        ])->assertCreated();

        $stored = (string) ProviderConnection::query()->value('credentials');

        expect($stored)->not->toBeEmpty()
            ->and($stored)->not->toContain('plaintext-secret')
            ->and($stored)->not->toContain('real-id');
    });

    it('records who stored them, and which keys, but never the values', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'real-id', 'client_secret' => 'plaintext-secret'],
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', AuditAction::IntegrationConnected->value)->first();

        expect($entry)->not->toBeNull()
            ->and($entry?->getAttribute('actor_id'))->toBe((string) $scenario->owner->getKey());

        $changes = json_encode($entry?->getAttribute('changes'));
        expect($changes)->toContain('client_secret')
            ->and($changes)->not->toContain('plaintext-secret');
    });

    it('rejects a credential key the provider does not use', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'a', 'client_secret' => 'b', 'developer_token' => 'c'],
        ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('credentials');
    });

    it('stays "not configured" when a required credential is absent', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/google_ads', [
            'credentials' => ['client_id' => 'a', 'client_secret' => 'b'],
        ])->assertCreated()->assertJsonPath('data.status', 'not_configured');
    });

    it('refuses configuration to a project manager', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($manager)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'a', 'client_secret' => 'b'],
        ])->assertForbidden();
    });

    it('keeps each workspace’s credentials separate', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');

        $this->actingAs($mine->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'mine', 'client_secret' => 'mine'],
        ])->assertCreated();

        $response = $this->actingAs($theirs->owner)->getJson('/api/integrations/providers');
        $meta = jsonRow($response->json('data'), 'provider', 'meta');

        expect($meta['status'])->toBe('not_configured');
    });
});

describe('the blocked external boundary', function (): void {
    it('refuses discovery with real credentials rather than inventing accounts', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'a-real-looking-id', 'client_secret' => 'a-real-looking-secret'],
        ])->assertCreated();

        $response = $this->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover');

        // 503 and an actionable message, never a plausible fake success.
        $response->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
            ->assertJsonPath('code', 'provider_not_configured')
            ->assertJsonPath('blocked_external', true);

        // And it must not blame the operator for credentials they did supply.
        $message = $response->json('message');

        expect($message)->toContain('has not been granted API access');
        expect($message)->not->toContain('Missing credentials');
    });

    it('refuses a provider with no published API at all', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/openai', [
            'credentials' => ['api_key' => SANDBOX],
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->postJson('/api/integrations/providers/openai/discover')
            ->assertStatus(Response::HTTP_NOT_IMPLEMENTED)
            ->assertJsonPath('code', 'provider_unavailable');
    });

    it('reports an honest health status for credentials we cannot verify', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => 'real', 'client_secret' => 'real'],
        ])->assertCreated();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers/meta/health');

        $response->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.status', 'configured');
        expect($response->json('data.message'))->toContain('has not been completed');
    });
});

describe('sandbox mode', function (): void {
    it('discovers clearly labelled sandbox accounts', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => SANDBOX, 'client_secret' => SANDBOX],
        ])->assertCreated();

        $response = $this->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover');

        $response->assertOk();
        expect($response->json('meta.count'))->toBeGreaterThan(0);

        foreach ($response->json('data') as $account) {
            // Nothing from a fixture may ever read as a client's own account.
            expect($account['name'])->toContain('SANDBOX');
        }
    });

    it('is idempotent: rediscovering refreshes rather than duplicates', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => SANDBOX, 'client_secret' => SANDBOX],
        ])->assertCreated();

        $first = $this->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover');
        $second = $this->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover');

        expect($second->json('meta.count'))->toBe($first->json('meta.count'))
            ->and(array_column($second->json('data'), 'id'))
            ->toBe(array_column($first->json('data'), 'id'));
    });

    it('reports healthy once running on fixtures', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => SANDBOX, 'client_secret' => SANDBOX],
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->getJson('/api/integrations/providers/meta/health')
            ->assertOk()
            ->assertJsonPath('data.healthy', true);
    });
});

describe('disconnecting', function (): void {
    it('destroys the stored credentials', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
            'credentials' => ['client_id' => SANDBOX, 'client_secret' => SANDBOX],
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->deleteJson('/api/integrations/providers/meta')
            ->assertNoContent();

        expect(ProviderConnection::query()->value('credentials'))->toBeNull();

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/providers');
        $meta = jsonRow($response->json('data'), 'provider', 'meta');
        expect($meta['status'])->toBe('not_configured');
    });
});
