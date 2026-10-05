<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use App\Infrastructure\Persistence\Eloquent\Model\ProviderConnection;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/**
 * Configure a sandbox connection and discover its accounts.
 *
 * @return list<array<string, mixed>>
 */
function sandboxAccounts(WorkspaceScenario $scenario): array
{
    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', [
        'credentials' => [
            'client_id' => AbstractProviderAdapter::SANDBOX_TOKEN,
            'client_secret' => AbstractProviderAdapter::SANDBOX_TOKEN,
        ],
    ])->assertCreated();

    return test()->actingAs($scenario->owner)
        ->postJson('/api/integrations/providers/meta/discover')
        ->json('data');
}

function projectIn(WorkspaceScenario $scenario, string $name = 'Ramadan Campaign'): Project
{
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return Project::factory()->forClient($client)->create(['name' => $name]);
}

it('starts with no bindings: discovery alone grants nothing', function (): void {
    $scenario = workspaceScenario();
    $project = projectIn($scenario);
    sandboxAccounts($scenario);

    // Accounts exist in the workspace, but no project can see any of them.
    $this->actingAs($scenario->owner)
        ->getJson("/api/projects/{$project->getKey()}/integrations")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

describe('granting access', function (): void {
    it('binds an account to a project and records who did it', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($scenario->owner)
            ->postJson("/api/projects/{$project->getKey()}/integrations", [
                'external_account_id' => $accounts[0]['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sync_enabled', true);

        $this->actingAs($scenario->owner)
            ->getJson("/api/projects/{$project->getKey()}/integrations")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_account.id', $accounts[0]['id']);

        expect(AuditLog::query()->where('action', AuditAction::IntegrationAccountBound->value)->exists())
            ->toBeTrue();
    });

    it('is idempotent rather than erroring on a repeat', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);
        $payload = ['external_account_id' => $accounts[0]['id']];

        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", $payload);
        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", $payload)
            ->assertCreated();

        expect(ProjectIntegrationBinding::query()->count())->toBe(1);
    });

    it('refuses to bind an account the provider reports as disabled', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $disabled = jsonRow($accounts, 'status', 'disabled');
        expect($disabled['id'])->toBeString();

        $this->actingAs($scenario->owner)
            ->postJson("/api/projects/{$project->getKey()}/integrations", [
                'external_account_id' => $disabled['id'],
            ])
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonPath('code', 'account_not_bindable');
    });

    it('refuses binding to a project manager: this is an access boundary', function (): void {
        $scenario = workspaceScenario();
        $manager = $scenario->addMember(SystemRole::ProjectManager);
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($manager)
            ->postJson("/api/projects/{$project->getKey()}/integrations", [
                'external_account_id' => $accounts[0]['id'],
            ])
            ->assertForbidden();

        expect(ProjectIntegrationBinding::query()->count())->toBe(0);
    });
});

describe('cross-tenant isolation', function (): void {
    it('cannot bind another workspace’s account to my project', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');

        $myProject = projectIn($mine);
        $theirAccounts = sandboxAccounts($theirs);

        $this->actingAs($mine->owner)
            ->postJson("/api/projects/{$myProject->getKey()}/integrations", [
                'external_account_id' => $theirAccounts[0]['id'],
            ])
            ->assertNotFound();

        expect(ProjectIntegrationBinding::query()->count())->toBe(0);
    });

    it('cannot bind my account to another workspace’s project', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');

        $myAccounts = sandboxAccounts($mine);
        $theirProject = projectIn($theirs);

        $this->actingAs($mine->owner)
            ->postJson("/api/projects/{$theirProject->getKey()}/integrations", [
                'external_account_id' => $myAccounts[0]['id'],
            ])
            ->assertNotFound();

        expect(ProjectIntegrationBinding::query()->count())->toBe(0);
    });

    it('never lists another workspace’s accounts', function (): void {
        $mine = workspaceScenario('Mine');
        $theirs = workspaceScenario('Theirs');
        sandboxAccounts($theirs);

        $this->actingAs($mine->owner)
            ->getJson('/api/integrations/accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });
});

describe('project-level visibility', function (): void {
    it('lets an assigned buyer see which accounts feed their project', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", [
            'external_account_id' => $accounts[0]['id'],
        ])->assertCreated();

        App\Infrastructure\Persistence\Eloquent\Model\ProjectMember::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $buyer->getKey(),
            'role_id' => $scenario->role(SystemRole::MediaBuyer)->getKey(),
        ]);

        // They can read the bindings without being able to administer them.
        $this->actingAs($buyer)
            ->getJson("/api/projects/{$project->getKey()}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });

    it('hides them from a buyer who is not assigned to the project', function (): void {
        $scenario = workspaceScenario();
        $buyer = $scenario->addMember(SystemRole::MediaBuyer);
        $project = projectIn($scenario);

        $this->actingAs($buyer)
            ->getJson("/api/projects/{$project->getKey()}/integrations")
            ->assertForbidden();
    });
});

describe('revoking and pausing', function (): void {
    it('revoking removes the project’s access immediately', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", [
            'external_account_id' => $accounts[0]['id'],
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->deleteJson("/api/projects/{$project->getKey()}/integrations/{$accounts[0]['id']}")
            ->assertNoContent();

        $this->actingAs($scenario->owner)
            ->getJson("/api/projects/{$project->getKey()}/integrations")
            ->assertJsonCount(0, 'data');

        // The account itself survives; only the grant is gone.
        expect(ExternalAccount::query()->whereKey($accounts[0]['id'])->exists())->toBeTrue()
            ->and(AuditLog::query()->where('action', AuditAction::IntegrationAccountUnbound->value)->exists())
            ->toBeTrue();
    });

    it('pausing keeps the binding but stops the sync', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", [
            'external_account_id' => $accounts[0]['id'],
        ])->assertCreated();

        $this->actingAs($scenario->owner)
            ->patchJson("/api/projects/{$project->getKey()}/integrations/{$accounts[0]['id']}", [
                'sync_enabled' => false,
            ])
            ->assertNoContent();

        $this->actingAs($scenario->owner)
            ->getJson("/api/projects/{$project->getKey()}/integrations")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sync_enabled', false);
    });

    it('reports how many projects use an account', function (): void {
        $scenario = workspaceScenario();
        $first = projectIn($scenario, 'First');
        $second = projectIn($scenario, 'Second');
        $accounts = sandboxAccounts($scenario);

        foreach ([$first, $second] as $project) {
            $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", [
                'external_account_id' => $accounts[0]['id'],
            ])->assertCreated();
        }

        $response = $this->actingAs($scenario->owner)->getJson('/api/integrations/accounts');
        $account = jsonRow($response->json('data'), 'id', $accounts[0]['id']);

        expect($account['binding_count'])->toBe(2);
    });

    it('removing a connection takes its accounts and bindings with it', function (): void {
        $scenario = workspaceScenario();
        $project = projectIn($scenario);
        $accounts = sandboxAccounts($scenario);

        $this->actingAs($scenario->owner)->postJson("/api/projects/{$project->getKey()}/integrations", [
            'external_account_id' => $accounts[0]['id'],
        ])->assertCreated();

        // Hard-deleting the connection cascades, by the schema's own rules.
        ProviderConnection::query()->forceDelete();

        expect(ExternalAccount::query()->count())->toBe(0)
            ->and(ProjectIntegrationBinding::query()->count())->toBe(0);
    });
});
