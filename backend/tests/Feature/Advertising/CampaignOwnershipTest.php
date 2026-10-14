<?php

declare(strict_types=1);

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/**
 * Which project owns a campaign.
 *
 * The derivation campaign → account → bindings → project is exact while an account serves one project, and
 * the bindings table is unique on `(project_id, external_account_id)` precisely so an account can serve
 * several. An agency running Brand Awareness, Lead Generation and a seasonal push out of one Google Ads
 * account is the normal case — and before this, every campaign on such an account had an owner nothing in
 * the product could name.
 *
 * @return list<array<string, mixed>>
 */
function discoveredAccounts(WorkspaceScenario $scenario): array
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

function projectNamed(WorkspaceScenario $scenario, string $name): Project
{
    $client = Client::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'name' => $name.' Client',
    ]);

    return Project::factory()->forClient($client)->create(['name' => $name]);
}

function bind(WorkspaceScenario $scenario, Project $project, string $accountId): void
{
    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$project->getKey()}/integrations", ['external_account_id' => $accountId])
        ->assertCreated();
}

function campaignOn(WorkspaceScenario $scenario, string $accountId, string $name): Campaign
{
    return Campaign::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'external_account_id' => $accountId,
        'provider' => 'meta',
        'external_id' => 'ext-'.bin2hex(random_bytes(4)),
        'name' => $name,
        'objective' => 'sales',
        'status' => 'active',
        'budget_mode' => 'daily',
        'synced_at' => now(),
    ]);
}

it('reads a campaign owner straight off the one project its account serves', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    $project = projectNamed($scenario, 'Brand Awareness');
    bind($scenario, $project, $accounts[0]['id']);
    campaignOn($scenario, $accounts[0]['id'], 'Ramadan Push');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership')
        ->assertOk()
        ->assertJsonPath('data.0.ownership', 'implied')
        ->assertJsonPath('data.0.project_name', 'Brand Awareness')
        ->assertJsonPath('data.0.needs_attention', false);
});

/* The case the column exists for. */
it('calls a campaign ambiguous when its account serves several projects', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    bind($scenario, projectNamed($scenario, 'Brand Awareness'), $accounts[0]['id']);
    bind($scenario, projectNamed($scenario, 'Lead Generation'), $accounts[0]['id']);
    campaignOn($scenario, $accounts[0]['id'], 'Ramadan Push');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership')
        ->assertOk()
        ->assertJsonPath('data.0.ownership', 'ambiguous')
        ->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.needs_attention', true)
        ->assertJsonCount(2, 'data.0.candidate_projects');
});

/*
 * Two ways to have no project, and they send somebody to different screens: one is fixed by assigning the
 * campaign, the other by binding the account. Collapsing them would send half the queue to the wrong place.
 */
it('distinguishes an unbound account from an ambiguous one', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    campaignOn($scenario, $accounts[0]['id'], 'Nobody owns me');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership')
        ->assertOk()
        ->assertJsonPath('data.0.ownership', 'unbound')
        ->assertJsonCount(0, 'data.0.candidate_projects');
});

it('settles an ambiguous campaign by assigning it, and records the grant', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    $brand = projectNamed($scenario, 'Brand Awareness');
    bind($scenario, $brand, $accounts[0]['id']);
    bind($scenario, projectNamed($scenario, 'Lead Generation'), $accounts[0]['id']);
    $campaign = campaignOn($scenario, $accounts[0]['id'], 'Ramadan Push');

    $this->actingAs($scenario->owner)
        ->putJson("/api/campaigns/{$campaign->getKey()}/project", ['project_id' => $brand->getKey()])
        ->assertOk()
        ->assertJsonPath('data.ownership', 'assigned')
        ->assertJsonPath('data.project_name', 'Brand Awareness');

    expect(AuditLog::query()->where('action', AuditAction::CampaignProjectAssigned->value)->exists())
        ->toBeTrue();
});

/*
 * The safety property. If an assignment could name any project, it would be a second and quieter way to
 * grant a project sight of an ad account — which is the one thing bindings exist to control, in an
 * installation that runs competing clients side by side.
 */
it('refuses to assign a campaign to a project its account is not bound to', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    bind($scenario, projectNamed($scenario, 'Brand Awareness'), $accounts[0]['id']);
    $stranger = projectNamed($scenario, 'Someone Else');
    $campaign = campaignOn($scenario, $accounts[0]['id'], 'Ramadan Push');

    $this->actingAs($scenario->owner)
        ->putJson("/api/campaigns/{$campaign->getKey()}/project", ['project_id' => $stranger->getKey()])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

    expect(Campaign::query()->whereKey($campaign->getKey())->value('project_id'))->toBeNull();
});

it('returns a campaign to its account’s bindings when the project is cleared', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    $brand = projectNamed($scenario, 'Brand Awareness');
    bind($scenario, $brand, $accounts[0]['id']);
    $campaign = campaignOn($scenario, $accounts[0]['id'], 'Ramadan Push');

    $this->actingAs($scenario->owner)
        ->putJson("/api/campaigns/{$campaign->getKey()}/project", ['project_id' => $brand->getKey()])
        ->assertOk();

    $this->actingAs($scenario->owner)
        ->putJson("/api/campaigns/{$campaign->getKey()}/project", ['project_id' => null])
        ->assertOk()
        /* Back to derived, and the one binding makes it implied rather than ambiguous. */
        ->assertJsonPath('data.ownership', 'implied');
});

it('counts every ownership state over the whole population, not the page', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    bind($scenario, projectNamed($scenario, 'Brand Awareness'), $accounts[0]['id']);
    bind($scenario, projectNamed($scenario, 'Lead Generation'), $accounts[0]['id']);

    foreach (range(1, 3) as $index) {
        campaignOn($scenario, $accounts[0]['id'], 'Ambiguous '.$index);
    }

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership?limit=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.counts.ambiguous', 3)
        ->assertJsonPath('meta.counts.assigned', 0);
});

it('filters to the queue that needs attention', function (): void {
    $scenario = workspaceScenario();
    $accounts = discoveredAccounts($scenario);
    $brand = projectNamed($scenario, 'Brand Awareness');
    bind($scenario, $brand, $accounts[0]['id']);
    campaignOn($scenario, $accounts[0]['id'], 'Settled');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership?ownership[]=ambiguous')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/ownership?ownership[]=nonsense')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'invalid_ownership');
});

/*
 * A media buyer holds `projects.view_assigned` and not `projects.view_all`, so their visible set is the
 * projects they are actually on. A campaign whose account reaches only other people's projects is not
 * theirs to see — and, because the candidate list is what the assign command validates against, a leak
 * here would be a leak there.
 */
it('shows a media buyer no campaign that reaches only projects they are not on', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);
    $accounts = discoveredAccounts($scenario);
    bind($scenario, projectNamed($scenario, 'Theirs'), $accounts[0]['id']);
    campaignOn($scenario, $accounts[0]['id'], 'Not for them');

    $this->actingAs($buyer)->getJson('/api/campaigns/ownership')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/* A project manager sees the whole workspace by role, which is what `projects.view_all` means. */
it('shows a project manager the agency-wide queue', function (): void {
    $scenario = workspaceScenario();
    $manager = $scenario->addMember(SystemRole::ProjectManager);
    $accounts = discoveredAccounts($scenario);
    bind($scenario, projectNamed($scenario, 'Theirs'), $accounts[0]['id']);
    campaignOn($scenario, $accounts[0]['id'], 'Visible to them');

    $this->actingAs($manager)->getJson('/api/campaigns/ownership')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
