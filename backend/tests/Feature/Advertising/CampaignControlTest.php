<?php

declare(strict_types=1);

use App\Application\Advertising\Query\GetCampaignControl;
use App\Domain\Access\SystemRole;
use App\Infrastructure\Integration\Adapter\AbstractProviderAdapter;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkspaceScenario;

/**
 * The Campaign Control Center.
 *
 * One surface answering "which of our campaigns needs me", so normal monitoring does not mean opening
 * Meta, Google, TikTok and Snapchat in four tabs. What is asserted here is mostly what it refuses to say:
 * no ROAS where revenue is not measured, no verdict on four days of data, no zero standing in for a
 * figure nobody reported.
 *
 * @return list<array<string, mixed>>
 */
function controlAccounts(WorkspaceScenario $scenario): array
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

function controlProject(WorkspaceScenario $scenario, string $accountId, string $name = 'Growth'): Project
{
    $client = Client::factory()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'name' => $name.' Client',
    ]);
    $project = Project::factory()->forClient($client)->create(['name' => $name]);

    test()->actingAs($scenario->owner)
        ->postJson("/api/projects/{$project->getKey()}/integrations", ['external_account_id' => $accountId])
        ->assertCreated();

    return $project;
}

function controlCampaign(
    WorkspaceScenario $scenario,
    string $accountId,
    string $name,
    string $objective = 'sales',
    string $status = 'active',
): Campaign {
    return Campaign::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'external_account_id' => $accountId,
        'provider' => 'meta',
        'external_id' => 'ext-'.bin2hex(random_bytes(4)),
        'name' => $name,
        'objective' => $objective,
        'status' => $status,
        'raw_status' => strtoupper($status),
        'budget_mode' => 'daily',
        'budget_minor' => 100_000,
        'currency' => 'SAR',
        'synced_at' => now(),
    ]);
}

/** Write `$days` days of figures ending yesterday, starting `$offset` days before that. */
function metricsFor(
    WorkspaceScenario $scenario,
    Campaign $campaign,
    int $days,
    int $offset = 0,
    ?int $spendMinor = 10_000,
    ?float $conversions = 5.0,
    ?int $revenueMinor = 40_000,
): void {
    for ($back = 0; $back < $days; $back++) {
        DailyMetric::query()->create([
            'workspace_id' => $scenario->workspace->getKey(),
            'external_account_id' => $campaign->getAttribute('external_account_id'),
            'provider' => 'meta',
            'entity_type' => 'campaign',
            'entity_id' => $campaign->getKey(),
            'external_entity_id' => (string) $campaign->getAttribute('external_id'),
            'campaign_id' => $campaign->getKey(),
            'date' => now()->subDays(1 + $offset + $back)->toDateString(),
            'currency' => 'SAR',
            'spend_minor' => $spendMinor,
            'revenue_minor' => $revenueMinor,
            'impressions' => 10_000,
            'reach' => 7_000,
            'clicks' => 200,
            'link_clicks' => 180,
            'conversions' => $conversions,
            'source' => 'sandbox',
        ]);
    }
}

it('shows the platform’s status and the product’s verdict side by side', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $campaign = controlCampaign($scenario, $accounts[0]['id'], 'Ramadan Push');
    metricsFor($scenario, $campaign, days: 28);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Ramadan Push')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.provider_status', 'ACTIVE')
        ->assertJsonPath('data.0.health', 'healthy')
        ->assertJsonPath('data.0.project_name', 'Growth');
});

/*
 * Rule 11's whole point: a campaign the platform calls ACTIVE can be the one most in need of somebody,
 * and a surface that showed only the platform's word would hide exactly that row.
 */
it('calls an active campaign critical when it has spent and recorded nothing', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $campaign = controlCampaign($scenario, $accounts[0]['id'], 'Silent');
    metricsFor($scenario, $campaign, days: 28, conversions: 0.0, revenueMinor: 0);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.0.health', 'critical')
        ->assertJsonPath('data.0.needs_attention', true);
});

/* Rule 33. A lead campaign has no revenue of its own, and a return figure on its row would be invented. */
it('refuses a ROAS for an objective that does not measure revenue', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $leads = controlCampaign($scenario, $accounts[0]['id'], 'Lead Gen', objective: 'leads');
    metricsFor($scenario, $leads, days: 28);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.objective', 'leads')
        ->assertJsonPath('data.0.roas', null)
        ->assertJsonPath('data.0.revenue_minor', null)
        /* The count is still there; only the figure it cannot support is withheld. */
        ->assertJsonPath('data.0.result', 140);
});

it('reads a reach campaign on reach rather than on purchases it never chased', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $reach = controlCampaign($scenario, $accounts[0]['id'], 'Brand', objective: 'reach');
    metricsFor($scenario, $reach, days: 28, conversions: 0.0);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.result', 196000)
        ->assertJsonPath('data.0.result_label', 'reach')
        /* Zero conversions is not a finding for an objective that counts none. */
        ->assertJsonPath('data.0.health', 'healthy')
        ->assertJsonPath('data.0.cost_per_result_minor', null);
});

it('says it cannot judge a campaign with four days of figures', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $young = controlCampaign($scenario, $accounts[0]['id'], 'New');
    metricsFor($scenario, $young, days: 4);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.health', 'insufficient_data')
        ->assertJsonPath('data.0.reported_days', 4);
});

it('counts every health state, including the ones with nobody in them', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Fine'), days: 28);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('meta.health_counts.healthy', 1)
        ->assertJsonPath('meta.health_counts.critical', 0)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.is_truncated', false);
});

it('filters by health, client and a search term', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Healthy One'), days: 28);
    metricsFor(
        $scenario,
        controlCampaign($scenario, $accounts[0]['id'], 'Silent One'),
        days: 28,
        conversions: 0.0,
    );

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?health[]=critical')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Silent One');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?search=Healthy')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Healthy One');
});

it('narrows to an ad account and reads the period the reader chose, ignoring one it does not offer', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Account One'), days: 28);

    $this->actingAs($scenario->owner)
        ->getJson('/api/campaigns/control?external_account_id='.$accounts[0]['id'])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($scenario->owner)
        ->getJson('/api/campaigns/control?external_account_id=00000000-0000-4000-8000-000000000000')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $window = fn (string $query): int => (int) round(
        (strtotime((string) $this->actingAs($scenario->owner)->getJson('/api/campaigns/control'.$query)->json('meta.window.to'))
        - strtotime((string) $this->actingAs($scenario->owner)->getJson('/api/campaigns/control'.$query)->json('meta.window.from'))) / 86_400,
    ) + 1;

    expect($window('?days=7'))->toBe(7)
        ->and($window('?days=90'))->toBe(90)
        ->and($window('?days=365'))->toBe(28);
});

it('offers to ask for a pause or a resume only where the platform’s write is documented and the state fits', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Running One'), days: 28);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Stopped One', status: 'paused'), days: 28);
    $elsewhere = controlCampaign($scenario, $accounts[0]['id'], 'Elsewhere One');
    $elsewhere->forceFill(['provider' => 'openai'])->save();
    metricsFor($scenario, $elsewhere, days: 28);

    /** @var list<array<string, mixed>> $data */
    $data = $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')->assertOk()->json('data');
    $rows = array_column($data, null, 'name');

    // Offered as a request, never as an action: the word is the state's, the control is the server's.
    expect($rows['Running One']['status_action'])->toBe('pause')
        ->and($rows['Stopped One']['status_action'])->toBe('resume')
        // ChatGPT Ads publishes no API to write through, so a control that could only be refused is not offered.
        ->and($rows['Elsewhere One']['status_action'])->toBeNull();
});

it('writes an approval request naming the platform’s own ids, and changes nothing about the campaign', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $campaign = controlCampaign($scenario, $accounts[0]['id'], 'Ramadan Push');
    metricsFor($scenario, $campaign, days: 28);

    $response = $this->actingAs($scenario->owner)
        ->postJson("/api/campaigns/{$campaign->getKey()}/status-request", ['action' => 'pause', 'reason' => 'Spend is up and nothing converts.'])
        ->assertCreated();

    $proposal = $response->json('data.proposal');

    expect($response->json('data.kind'))->toBe('campaign_pause')
        ->and($proposal['external_campaign_id'])->toBe($campaign->getAttribute('external_id'))
        ->and($proposal['provider'])->toBe('meta')
        ->and($proposal['change'])->toBe('pause')
        // Asking is not doing: the campaign is exactly as it was.
        ->and(DB::table('campaigns')->where('id', $campaign->getKey())->value('status'))->toBe('active');
});

it('refuses to ask for what makes no sense, in words', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $running = controlCampaign($scenario, $accounts[0]['id'], 'Running One');
    metricsFor($scenario, $running, days: 28);
    $elsewhere = controlCampaign($scenario, $accounts[0]['id'], 'Elsewhere One');
    $elsewhere->forceFill(['provider' => 'openai'])->save();
    metricsFor($scenario, $elsewhere, days: 28);

    $this->actingAs($scenario->owner)
        ->postJson("/api/campaigns/{$running->getKey()}/status-request", ['action' => 'resume', 'reason' => 'It should be on.'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'wrong_state');

    $this->actingAs($scenario->owner)
        ->postJson("/api/campaigns/{$elsewhere->getKey()}/status-request", ['action' => 'pause', 'reason' => 'Please stop it.'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'provider_not_documented');

    $this->actingAs($scenario->owner)
        ->postJson('/api/campaigns/00000000-0000-4000-8000-000000000000/status-request', ['action' => 'pause', 'reason' => 'Anything at all.'])
        ->assertNotFound();
});

/*
 * A filter the server did not understand returns a page the reader believes is narrowed and is not,
 * which is worse than an error.
 */
it('refuses a filter value it does not recognise instead of ignoring it', function (): void {
    $scenario = workspaceScenario();

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?health[]=excellent')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonPath('code', 'invalid_health');
});

it('puts the worst first, whatever the spend behind it', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);

    metricsFor(
        $scenario,
        controlCampaign($scenario, $accounts[0]['id'], 'Big and fine'),
        days: 28,
        spendMinor: 900_000,
    );
    metricsFor(
        $scenario,
        controlCampaign($scenario, $accounts[0]['id'], 'Small and broken'),
        days: 28,
        spendMinor: 1_000,
        conversions: 0.0,
    );

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Small and broken');
});

it('shows a media buyer nothing from projects they are not on', function (): void {
    $scenario = workspaceScenario();
    $buyer = $scenario->addMember(SystemRole::MediaBuyer);
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Theirs'), days: 28);

    $this->actingAs($buyer)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('reads health over a four-week window ending yesterday', function (): void {
    expect(GetCampaignControl::WINDOW_DAYS)->toBe(28);

    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $campaign = controlCampaign($scenario, $accounts[0]['id'], 'Older than the window');

    /* Entirely before the window: present in the table, absent from the figures. */
    metricsFor($scenario, $campaign, days: 10, offset: 40);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')
        ->assertOk()
        ->assertJsonPath('data.0.reported_days', 0)
        ->assertJsonPath('data.0.spend_minor', null)
        ->assertJsonPath('data.0.health', 'insufficient_data');
});

it('sorts on the server, nulls last, and refuses a sort it does not know', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Cheap'), days: 28, spendMinor: 1_000);
    metricsFor($scenario, controlCampaign($scenario, $accounts[0]['id'], 'Dear'), days: 28, spendMinor: 90_000);

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?sort=spend')
        ->assertOk()->assertJsonPath('data.0.name', 'Dear');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?sort=spend&direction=desc')
        ->assertOk()->assertJsonPath('data.0.name', 'Cheap');

    $this->actingAs($scenario->owner)->getJson('/api/campaigns/control?sort=vibes')
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonPath('code', 'invalid_sort');
});

it('gives each row a fourteen-day trend with unreported days as null, and utilisation only for daily budgets', function (): void {
    $scenario = workspaceScenario();
    $accounts = controlAccounts($scenario);
    controlProject($scenario, $accounts[0]['id']);
    $campaign = controlCampaign($scenario, $accounts[0]['id'], 'Trended');
    metricsFor($scenario, $campaign, days: 5, spendMinor: 50_000);

    $row = $this->actingAs($scenario->owner)->getJson('/api/campaigns/control')->assertOk()->json('data.0');

    expect($row['trend'])->toHaveCount(14)
        ->and(array_filter($row['trend'], static fn ($v): bool => $v === null))->not->toBeEmpty()
        ->and($row['budget_utilisation'])->toBe(0.5);
});

it('publishes the headline spend and its trend over everything matched, per currency', function (): void {
    $w = Tests\Support\SyncedWorkspace::with([App\Domain\Integration\Provider::Meta->value => 'act_sandbox_1001']);

    $meta = test()->actingAs($w->scenario->owner)->getJson('/api/campaigns/control?limit=1')->assertOk()->json('meta');

    $perPage = test()->actingAs($w->scenario->owner)->getJson('/api/campaigns/control?limit=200')->json('data');
    $expected = 0;

    foreach ($perPage as $row) {
        $expected += (int) ($row['spend_minor'] ?? 0);
    }

    expect($meta['spend_by_currency'])->not->toBeEmpty()
        ->and(array_sum(array_column($meta['spend_by_currency'], 'spend_minor')))->toBe($expected)
        // One value per day of the fourteen-day trend window... or the assessment window, whichever the server uses.
        ->and(count($meta['spend_trend'][0]['points']))->toBeGreaterThan(1)
        // The headline describes the matched set, not the page: asking for one row changes nothing.
        ->and($meta['spend_by_currency'])->toBe(
            test()->actingAs($w->scenario->owner)->getJson('/api/campaigns/control?limit=200')->json('meta.spend_by_currency'),
        );
});

it('answers for exactly one campaign when asked by id, with the same verdict as the list', function (): void {
    $w = Tests\Support\SyncedWorkspace::with([App\Domain\Integration\Provider::Meta->value => 'act_sandbox_1001']);
    $owner = $w->scenario->owner;
    $all = test()->actingAs($owner)->getJson('/api/campaigns/control?limit=200')->json('data');
    $one = $all[0];

    $single = test()->actingAs($owner)->getJson('/api/campaigns/control?campaign_id[]='.$one['id'])->assertOk()->json('data');

    expect($single)->toHaveCount(1)
        ->and($single[0]['id'])->toBe($one['id'])
        ->and($single[0]['health'])->toBe($one['health'])
        ->and($single[0]['spend_minor'])->toBe($one['spend_minor']);
});
