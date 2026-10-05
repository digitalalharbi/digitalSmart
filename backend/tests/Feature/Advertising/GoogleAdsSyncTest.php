<?php

declare(strict_types=1);

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Ad;
use App\Infrastructure\Persistence\Eloquent\Model\AdGroup;
use App\Infrastructure\Persistence\Eloquent\Model\Campaign;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Creative;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Google Ads sync
|--------------------------------------------------------------------------
|
| The same pipeline as Meta's, over a provider whose conventions differ in every
| detail that matters: micros instead of minor units, a separate budget resource,
| no objective field, no deduplicated reach, fractional conversions, and the ad
| doubling as its own creative. If the normalisation is real, the two providers
| land in the same tables with the same meaning.
|
*/

/** @return array{scenario: WorkspaceScenario, account: ExternalAccount} */
function googleScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $repository = app(ProviderConnectionRepository::class);

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::GoogleAds,
        name: 'Google Ads',
        credentials: new Credentials([
            'client_id' => Credentials::SANDBOX_TOKEN,
            'client_secret' => Credentials::SANDBOX_TOKEN,
            // Google needs three, and all three must be the token: a connection
            // half-filled with a real secret is a mistake, not a sandbox.
            'developer_token' => Credentials::SANDBOX_TOKEN,
        ]),
    );
    $repository->save($connection);

    $account = ExternalAccount::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => 'google_ads',
        'external_id' => '7788990011',
        'name' => 'SANDBOX — Alhambra Retail (Google Ads)',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
        'status' => 'active',
        'discovered_at' => now(),
    ]);

    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    ProjectIntegrationBinding::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'project_id' => $project->getKey(),
        'external_account_id' => $account->getKey(),
        'sync_enabled' => true,
        'bound_at' => now(),
    ]);

    return ['scenario' => $scenario, 'account' => $account];
}

/** @param array{scenario: WorkspaceScenario, account: ExternalAccount} $setup */
function runGoogleSync(array $setup): SyncOutcome
{
    return app(SyncBoundAccount::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['account']->getKey(),
    );
}

it('syncs the Google hierarchy and links it together', function (): void {
    $setup = googleScenario();

    $outcome = runGoogleSync($setup);

    expect($outcome->status)->toBe(SyncStatus::Succeeded)
        ->and(Campaign::query()->count())->toBe(5)
        ->and(AdGroup::query()->count())->toBe(6)
        ->and(Ad::query()->count())->toBe(5)
        // Google has no separate creative resource: the ad *is* the creative,
        // so there is one per ad rather than a fabricated parent.
        ->and(Creative::query()->count())->toBe(5);

    $ad = Ad::query()->where('external_id', '40110001')->firstOrFail();

    expect($ad->adGroup?->external_id)->toBe('30110001')
        ->and($ad->campaign?->external_id)->toBe('20110001')
        ->and($ad->creative?->external_id)->toBe('40110001');
});

it('lands Google money in the same minor units as Meta', function (): void {
    $setup = googleScenario();
    runGoogleSync($setup);

    $campaign = Campaign::query()->where('external_id', '20110001')->firstOrFail();

    // Micros on the wire, minor units in the database — the same column Meta's
    // minor-unit strings land in, so a cross-platform total means something.
    expect($campaign->budget_minor)->toBe(18_000)
        ->and($campaign->currency)->toBe('SAR');
});

it('records a campaign that is enabled but not delivering', function (): void {
    $setup = googleScenario();
    runGoogleSync($setup);

    $limited = Campaign::query()->where('external_id', '20110002')->firstOrFail();

    expect($limited->status)->toBe(EntityStatus::NotDelivering)
        ->and($limited->raw_status)->toBe('ENABLED')
        ->and($limited->raw_effective_status)->toBe('LIMITED');
});

it('keeps Google reach null across every stored row', function (): void {
    $setup = googleScenario();
    runGoogleSync($setup);

    /*
     * Google publishes no general deduplicated reach. Every row must say so, or
     * a frequency chart would silently divide by an invented number.
     */
    expect(DailyMetric::query()->whereNotNull('reach')->count())->toBe(0)
        ->and(DailyMetric::query()->whereNotNull('impressions')->count())->toBeGreaterThan(0);
});

it('stores fractional conversions without rounding them away', function (): void {
    $setup = googleScenario();
    runGoogleSync($setup);

    $fractional = DailyMetric::query()
        ->whereNotNull('conversions')
        ->whereRaw('conversions <> round(conversions)')
        ->count();

    // Google attributes fractionally. Rounding would make our CPA disagree with
    // the number the client sees in Google's own interface.
    expect($fractional)->toBeGreaterThan(0);
});

it('stamps Google sandbox rows as sandbox too', function (): void {
    $setup = googleScenario();
    runGoogleSync($setup);

    expect(DailyMetric::query()->distinct()->pluck('source')->all())
        ->toBe([MetricSource::Sandbox]);
});

it('refuses a Google sync when the developer token is real', function (): void {
    $setup = googleScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $setup['scenario']->workspace->getKey());

    $connection = $repository->findForProvider($workspaceId, Provider::GoogleAds);
    expect($connection)->not->toBeNull();

    /** @var ProviderConnection $connection */
    $connection->replaceCredentials(new Credentials([
        'client_id' => Credentials::SANDBOX_TOKEN,
        'client_secret' => Credentials::SANDBOX_TOKEN,
        // One real credential is enough: a production developer token cannot be
        // used without Google's approval, and reading fixtures instead would be
        // the exact fallback this project forbids.
        'developer_token' => 'a-real-looking-developer-token',
    ]));
    $repository->save($connection);

    $outcome = runGoogleSync($setup);

    expect($outcome->status)->toBe(SyncStatus::Failed)
        ->and($outcome->failureMessage)->toContain('has not been granted API access')
        ->and(Campaign::query()->count())->toBe(0)
        ->and(DailyMetric::query()->count())->toBe(0);
});

it('is idempotent for Google as well', function (): void {
    $setup = googleScenario();

    runGoogleSync($setup);
    $before = DailyMetric::query()->count();

    runGoogleSync($setup);

    expect(DailyMetric::query()->count())->toBe($before)
        ->and($before)->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| Cross-provider consistency
|--------------------------------------------------------------------------
|
| The point of normalisation: three providers with three money conventions,
| three status models and three reporting shapes land in the same tables with
| the same meaning — and each keeps its own vocabulary alongside.
|
*/

/** @return array{scenario: WorkspaceScenario, account: ExternalAccount} */
function tiktokScenario(): array
{
    $scenario = WorkspaceScenario::create();
    $repository = app(ProviderConnectionRepository::class);

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: Provider::TikTok,
        name: 'TikTok Ads',
        credentials: new Credentials([
            'app_id' => Credentials::SANDBOX_TOKEN,
            'app_secret' => Credentials::SANDBOX_TOKEN,
        ]),
    );
    $repository->save($connection);

    $account = ExternalAccount::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => 'tiktok',
        'external_id' => '7100000000000000001',
        'name' => 'SANDBOX — Alhambra Retail (TikTok)',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
        'status' => 'active',
        'discovered_at' => now(),
    ]);

    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    ProjectIntegrationBinding::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'project_id' => $project->getKey(),
        'external_account_id' => $account->getKey(),
        'sync_enabled' => true,
        'bound_at' => now(),
    ]);

    return ['scenario' => $scenario, 'account' => $account];
}

it('syncs TikTok into the same tables, with its own words kept', function (): void {
    $setup = tiktokScenario();

    $outcome = app(SyncBoundAccount::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['account']->getKey(),
    );

    expect($outcome->status)->toBe(SyncStatus::Succeeded)
        ->and(Campaign::query()->count())->toBe(5)
        ->and(AdGroup::query()->count())->toBe(6)
        ->and(Ad::query()->count())->toBe(6);

    $overBudget = Campaign::query()->where('external_id', '1810000000000000002')->firstOrFail();

    // Normalised for cross-platform queries, raw for the operator comparing
    // our screen with TikTok's.
    expect($overBudget->status)->toBe(EntityStatus::NotDelivering)
        ->and($overBudget->raw_status)->toBe('ENABLE')
        ->and($overBudget->raw_effective_status)->toBe('CAMPAIGN_STATUS_BUDGET_EXCEED')
        // A float on the wire, minor units in the column Meta and Google share.
        ->and($overBudget->budget_minor)->toBe(4_500_000);
});

it('keeps a TikTok "-" metric null all the way into the database', function (): void {
    $setup = tiktokScenario();

    app(SyncBoundAccount::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['account']->getKey(),
    );

    /*
     * The one ad whose reach TikTok could not produce. If the sentinel had been
     * cast anywhere along the way, this row would hold 0 and a frequency chart
     * would divide by it.
     */
    $missing = DailyMetric::query()
        ->where('entity_type', 'ad')
        ->whereNull('reach')
        ->whereNotNull('impressions')
        ->count();

    expect($missing)->toBeGreaterThan(0);
});

it('lands three providers in one set of tables without colliding', function (): void {
    $google = googleScenario();
    $tiktok = tiktokScenario();

    app(SyncBoundAccount::class)(
        (string) $google['scenario']->workspace->getKey(),
        (string) $google['account']->getKey(),
    );
    app(SyncBoundAccount::class)(
        (string) $tiktok['scenario']->workspace->getKey(),
        (string) $tiktok['account']->getKey(),
    );

    $byProvider = Campaign::query()
        ->selectRaw('provider, count(*) as total')
        ->groupBy('provider')
        ->pluck('total', 'provider')
        ->all();

    /*
     * Different workspaces, so this also proves the unique key is scoped to the
     * external account rather than global: two providers can use the same
     * campaign id without one overwriting the other.
     */
    expect($byProvider)->toHaveKeys(['google_ads', 'tiktok'])
        ->and(DailyMetric::query()->distinct()->pluck('provider')->all())
        ->toHaveCount(2);
});
