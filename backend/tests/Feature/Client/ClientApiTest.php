<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Client\ClientStatus;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Symfony\Component\HttpFoundation\Response;

it('requires authentication', function (): void {
    $this->getJson('/api/clients')->assertUnauthorized();
});

describe('listing', function (): void {
    it('returns only clients of the active workspace', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');

        Client::factory()->count(2)->create(['workspace_id' => $mine->workspace->getKey()]);
        Client::factory()->count(3)->create(['workspace_id' => $theirs->workspace->getKey()]);

        $response = $this->actingAs($mine->owner)->getJson('/api/clients');

        $response->assertOk()->assertJsonCount(2, 'data');
        expect($response->json('meta.total'))->toBe(2);
    });

    it('counts only live projects', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
        Project::factory()->count(2)->forClient($client)->create();
        Project::factory()->forClient($client)->create()->delete();

        $this->actingAs($scenario->owner)
            ->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('data.0.project_count', 2);
    });

    it('filters by status and search term', function (): void {
        $scenario = workspaceScenario();
        $workspaceId = $scenario->workspace->getKey();

        Client::factory()->create(['workspace_id' => $workspaceId, 'name' => 'Alpha Retail', 'status' => 'active']);
        Client::factory()->create(['workspace_id' => $workspaceId, 'name' => 'Beta Foods', 'status' => 'paused']);

        $this->actingAs($scenario->owner)
            ->getJson('/api/clients?status[]=paused')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Foods');

        $this->actingAs($scenario->owner)
            ->getJson('/api/clients?search=alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Retail');
    });

    it('paginates and caps the page size', function (): void {
        $scenario = workspaceScenario();
        Client::factory()->count(30)->create(['workspace_id' => $scenario->workspace->getKey()]);

        $response = $this->actingAs($scenario->owner)->getJson('/api/clients?per_page=500');

        $response->assertOk()->assertJsonPath('meta.per_page', 100);
        expect($response->json('meta.total'))->toBe(30);
    });

    it('ignores an unknown sort column instead of trusting it', function (): void {
        $scenario = workspaceScenario();
        Client::factory()->count(2)->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->getJson('/api/clients?sort=(select+1)&direction=sideways')
            ->assertOk();
    });
});

describe('permissions', function (): void {
    it('lets a project manager create a client', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);

        $this->actingAs($manager)
            ->postJson('/api/clients', ['name' => 'Acme Retail'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Retail')
            ->assertJsonPath('data.status', ClientStatus::Prospect->value);
    });

    it('refuses creation to a creative, naming the missing permission', function (): void {
        $scenario = workspaceScenario();
        $creative = $scenario->addMember(SystemRole::Creative);

        $this->actingAs($creative)
            ->postJson('/api/clients', ['name' => 'Acme Retail'])
            ->assertForbidden()
            ->assertJsonPath('required_permission', 'clients.create');

        expect(Client::query()->count())->toBe(0);
    });

    it('hides the client list entirely from a client viewer', function (): void {
        $scenario = workspaceScenario();
        $viewer = $scenario->addMember(SystemRole::ClientViewer);

        $this->actingAs($viewer)->getJson('/api/clients')->assertForbidden();
    });

    it('refuses deletion to a media buyer', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($buyer)
            ->deleteJson("/api/clients/{$client->getKey()}")
            ->assertForbidden();

        expect(Client::query()->whereKey($client->getKey())->exists())->toBeTrue();
    });
});

describe('isolation', function (): void {
    it('reports a client from another workspace as not found, not forbidden', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $foreign = Client::factory()->create(['workspace_id' => $theirs->workspace->getKey()]);

        // 403 would confirm the record exists. 404 discloses nothing.
        $this->actingAs($mine->owner)
            ->getJson("/api/clients/{$foreign->getKey()}")
            ->assertNotFound();
    });

    it('refuses to update a client in another workspace', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $foreign = Client::factory()->create([
            'workspace_id' => $theirs->workspace->getKey(),
            'name' => 'Untouched',
        ]);

        $this->actingAs($mine->owner)
            ->putJson("/api/clients/{$foreign->getKey()}", ['name' => 'Hijacked'])
            ->assertNotFound();

        expect($foreign->fresh()?->getAttribute('name'))->toBe('Untouched');
    });

    it('refuses to delete a client in another workspace', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        $foreign = Client::factory()->create(['workspace_id' => $theirs->workspace->getKey()]);

        $this->actingAs($mine->owner)
            ->deleteJson("/api/clients/{$foreign->getKey()}")
            ->assertNotFound();

        expect(Client::query()->whereKey($foreign->getKey())->exists())->toBeTrue();
    });
});

describe('creating', function (): void {
    it('derives a unique slug when names collide', function (): void {
        $scenario = workspaceScenario();

        $first = $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'Acme Retail']);
        $second = $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'Acme Retail']);

        expect($first->json('data.slug'))->toBe('acme-retail')
            ->and($second->json('data.slug'))->toBe('acme-retail-2');
    });

    it('falls back to a generated slug for a non-latin name', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->postJson('/api/clients', ['name' => 'شركة الاتصالات']);

        $response->assertCreated();
        expect($response->json('data.slug'))->toMatch('/^[a-z0-9-]+$/');
    });

    it('validates its input', function (): void {
        $scenario = workspaceScenario();

        $this->actingAs($scenario->owner)
            ->postJson('/api/clients', ['name' => 'A', 'website' => 'not-a-url', 'primary_contact_email' => 'nope'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors(['name', 'website', 'primary_contact_email']);
    });

    it('normalises the currency and records an audit entry', function (): void {
        $scenario = workspaceScenario();

        $response = $this->actingAs($scenario->owner)->postJson('/api/clients', [
            'name' => 'Acme Retail',
            'default_currency' => 'sar',
        ]);

        $response->assertCreated()->assertJsonPath('data.default_currency', 'SAR');

        expect(AuditLog::query()
            ->where('action', AuditAction::ClientCreated->value)
            ->where('auditable_id', $response->json('data.id'))
            ->where('workspace_id', $scenario->workspace->getKey())
            ->exists())->toBeTrue();
    });
});

describe('status changes', function (): void {
    it('moves a prospect to active', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->status(ClientStatus::Prospect)
            ->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->patchJson("/api/clients/{$client->getKey()}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    });

    it('rejects an illegal transition with the domain error code', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->status(ClientStatus::Archived)
            ->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->patchJson("/api/clients/{$client->getKey()}/status", ['status' => 'active'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'invalid_status_transition');

        expect($client->fresh()?->getAttribute('status'))->toBe('archived');
    });

    it('rejects a status that is not in the enum', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->patchJson("/api/clients/{$client->getKey()}/status", ['status' => 'exploded'])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonValidationErrors('status');
    });
});

describe('deleting', function (): void {
    it('soft-deletes so history survives', function (): void {
        $scenario = workspaceScenario();
        $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

        $this->actingAs($scenario->owner)
            ->deleteJson("/api/clients/{$client->getKey()}")
            ->assertNoContent();

        expect(Client::query()->whereKey($client->getKey())->exists())->toBeFalse()
            ->and(Client::withTrashed()->whereKey($client->getKey())->exists())->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::ClientDeleted->value)->exists())->toBeTrue();
    });
});
