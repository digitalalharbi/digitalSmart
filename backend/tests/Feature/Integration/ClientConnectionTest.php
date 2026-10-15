<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/**
 * Credentials a client granted the agency directly.
 *
 * The risk this guards is specific: a client-owned connection carries one client's access, and letting
 * another client's project bind its accounts would spend that access on a competitor's reporting.
 */
/** @return array<string, string> */
function sandboxCredentials(): array
{
    return [
        'client_id' => AbstractProviderAdapter::SANDBOX_TOKEN,
        'client_secret' => AbstractProviderAdapter::SANDBOX_TOKEN,
    ];
}

/** @return array{0: WorkspaceScenario, 1: Client, 2: Project, 3: Client, 4: Project} */
function twoClients(): array
{
    $scenario = workspaceScenario();
    $a = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'Alhambra']);
    $b = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey(), 'name' => 'Najd']);

    return [$scenario, $a, Project::factory()->forClient($a)->create(), $b, Project::factory()->forClient($b)->create()];
}

it('records a connection a client granted, beside the agency’s own', function (): void {
    [$scenario, $alhambra] = twoClients();

    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', ['credentials' => sandboxCredentials()])->assertCreated();

    $id = test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', [
        'provider' => 'meta', 'client_id' => (string) $alhambra->getKey(), 'credentials' => sandboxCredentials(),
    ])->assertCreated()->assertJsonPath('data.owner', 'client')->json('data.id');

    $list = test()->actingAs($scenario->owner)->getJson('/api/integrations/connections')->assertOk()->json('data');

    $byId = array_column($list, null, 'id');

    expect($list)->toHaveCount(2)
        ->and($list[0]['owner'])->toBe('agency')
        ->and($byId[$id]['label'])->toBe('Meta Ads — Alhambra')
        ->and(json_encode($list))->not->toContain(AbstractProviderAdapter::SANDBOX_TOKEN);

    expect(AuditLog::query()->where('action', AuditAction::IntegrationConnected->value)->count())->toBe(2);
});

it('refuses a second connection from the same client for the same platform', function (): void {
    [$scenario, $alhambra] = twoClients();
    $payload = ['provider' => 'meta', 'client_id' => (string) $alhambra->getKey(), 'credentials' => sandboxCredentials()];

    test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', $payload)->assertCreated();
    test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('code', 'connection_already_exists');
});

it('discovers on the client’s connection with the client’s credentials, not the agency’s', function (): void {
    [$scenario, $alhambra] = twoClients();
    $id = test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', [
        'provider' => 'meta', 'client_id' => (string) $alhambra->getKey(), 'credentials' => sandboxCredentials(),
    ])->json('data.id');

    test()->actingAs($scenario->owner)->postJson("/api/integrations/connections/{$id}/discover")
        ->assertOk()->assertJsonPath('data.discovered', fn ($n) => $n > 0);

    $queue = test()->actingAs($scenario->owner)->getJson('/api/integrations/account-queue')->assertOk();

    expect($queue->json('data.0.owner_client.name'))->toBe('Alhambra')
        ->and($queue->json('meta.counts.unassigned'))->toBeGreaterThan(0);
});

/* The isolation rule the whole feature exists for. */
it('refuses to bind one client’s account to another client’s project', function (): void {
    [$scenario, $alhambra, $alhambraProject, , $najdProject] = twoClients();
    $id = test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', [
        'provider' => 'meta', 'client_id' => (string) $alhambra->getKey(), 'credentials' => sandboxCredentials(),
    ])->json('data.id');
    test()->actingAs($scenario->owner)->postJson("/api/integrations/connections/{$id}/discover");
    $account = test()->actingAs($scenario->owner)->getJson('/api/integrations/account-queue?state[]=unassigned')
        ->json('data.0.id');

    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$najdProject->getKey()}/integrations", ['external_account_id' => $account])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('code', 'account_not_bindable');

    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$alhambraProject->getKey()}/integrations", ['external_account_id' => $account])
        ->assertCreated();
});

it('lets an agency-owned connection serve any client', function (): void {
    [$scenario, , $alhambraProject, , $najdProject] = twoClients();
    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', ['credentials' => sandboxCredentials()]);
    $account = test()->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover')->json('data.0.id');

    foreach ([$alhambraProject, $najdProject] as $project) {
        test()->actingAs($scenario->owner)
            ->postJson("/api/projects/{$project->getKey()}/integrations", ['external_account_id' => $account])
            ->assertCreated();
    }
});

it('ignores an unassigned account without deleting it, and will not ignore a bound one', function (): void {
    [$scenario, , $project] = twoClients();
    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', ['credentials' => sandboxCredentials()]);
    $accounts = test()->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover')->json('data');
    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$project->getKey()}/integrations", ['external_account_id' => $accounts[0]['id']]);

    test()->actingAs($scenario->owner)->postJson("/api/integrations/accounts/{$accounts[0]['id']}/ignore")
        ->assertNotFound();

    test()->actingAs($scenario->owner)->postJson("/api/integrations/accounts/{$accounts[1]['id']}/ignore")->assertNoContent();

    $counts = test()->actingAs($scenario->owner)->getJson('/api/integrations/account-queue')->json('meta.counts');
    expect($counts['ignored'])->toBe(1);

    test()->actingAs($scenario->owner)->deleteJson("/api/integrations/accounts/{$accounts[1]['id']}/ignore")->assertNoContent();
    expect(test()->actingAs($scenario->owner)->getJson('/api/integrations/account-queue')->json('meta.counts.ignored'))->toBe(0);
});

it('keeps the unassigned queue and the technical detail from people who cannot act on them', function (): void {
    [$scenario, , $project] = twoClients();
    $manager = $scenario->addMember(SystemRole::ProjectManager);
    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', ['credentials' => sandboxCredentials()]);
    test()->actingAs($scenario->owner)->postJson('/api/integrations/providers/meta/discover');

    test()->actingAs($manager)->postJson('/api/integrations/connections', [
        'provider' => 'meta', 'client_id' => (string) $project->client_id, 'credentials' => sandboxCredentials(),
    ])->assertForbidden();

    $connections = test()->actingAs($manager)->getJson('/api/integrations/connections')->assertOk()->json('data');
    expect($connections[0]['advanced'])->toBeNull();
});

it('disconnects only the connection it was asked to', function (): void {
    [$scenario, $alhambra] = twoClients();
    test()->actingAs($scenario->owner)->putJson('/api/integrations/providers/meta', ['credentials' => sandboxCredentials()]);
    $client = test()->actingAs($scenario->owner)->postJson('/api/integrations/connections', [
        'provider' => 'meta', 'client_id' => (string) $alhambra->getKey(), 'credentials' => sandboxCredentials(),
    ])->json('data.id');

    test()->actingAs($scenario->owner)->deleteJson("/api/integrations/connections/{$client}")->assertNoContent();

    /*
     * Isolation, asserted where it matters: the stored credentials. A disconnect that resolved by provider
     * would have destroyed the agency's and left the client's.
     */
    $stored = DB::table('provider_connections')->pluck('credentials', 'owner_type')->all();

    expect($stored['agency'])->not->toBeNull()
        ->and($stored['client'])->toBeNull();
});
