<?php

declare(strict_types=1);

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\ProviderErrorCategory;
use App\Domain\Advertising\SyncKind;
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
use App\Infrastructure\Persistence\Eloquent\Model\ConversionMetric;
use App\Infrastructure\Persistence\Eloquent\Model\Creative;
use App\Infrastructure\Persistence\Eloquent\Model\CreativeAsset;
use App\Infrastructure\Persistence\Eloquent\Model\DailyMetric;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use App\Infrastructure\Persistence\Eloquent\Model\SyncCursor;
use App\Infrastructure\Persistence\Eloquent\Model\SyncRun;
use App\Interface\Queue\SyncBoundAccountJob;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Advertising sync
|--------------------------------------------------------------------------
|
| Runs the real use case against the real store, over SANDBOX fixtures that hold
| real Meta API shapes. What is being tested is not that data appears, but that
| it appears exactly once, attached to the right parents, labelled with where it
| came from, and only for accounts a project was actually given.
|
*/

/**
 * Store Meta credentials the way the application does — through the repository,
 * so the real cipher runs and the test cannot drift from production behaviour.
 *
 * @param  array<string, string>  $values
 */
function storeCredentials(WorkspaceScenario $scenario, array $values): ProviderConnection
{
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());

    $existing = $repository->findForProvider($workspaceId, Provider::Meta);

    if ($existing instanceof ProviderConnection) {
        $existing->replaceCredentials(new Credentials($values));
        $repository->save($existing);

        return $existing;
    }

    $connection = ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: $workspaceId,
        provider: Provider::Meta,
        name: 'Meta Ads',
        credentials: new Credentials($values),
    );

    $repository->save($connection);

    return $connection;
}

/**
 * A workspace with a sandbox Meta connection and one bound, sync-enabled account.
 *
 * @return array{scenario: WorkspaceScenario, account: ExternalAccount, binding: ProjectIntegrationBinding}
 */
function syncScenario(bool $syncEnabled = true, string $externalId = 'act_sandbox_1001'): array
{
    $scenario = WorkspaceScenario::create();

    $connection = storeCredentials($scenario, [
        'client_id' => Credentials::SANDBOX_TOKEN,
        'client_secret' => Credentials::SANDBOX_TOKEN,
    ]);

    $account = ExternalAccount::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'provider_connection_id' => $connection->id->value,
        'provider' => 'meta',
        'external_id' => $externalId,
        'name' => 'SANDBOX — Alhambra Retail (Meta)',
        'currency' => 'SAR',
        'timezone' => 'Asia/Riyadh',
        'status' => 'active',
        'discovered_at' => now(),
    ]);

    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create();

    $binding = ProjectIntegrationBinding::query()->create([
        'workspace_id' => $scenario->workspace->getKey(),
        'project_id' => $project->getKey(),
        'external_account_id' => $account->getKey(),
        'sync_enabled' => $syncEnabled,
        'bound_at' => now(),
    ]);

    return ['scenario' => $scenario, 'account' => $account, 'binding' => $binding];
}

/** @param array{scenario: WorkspaceScenario, account: ExternalAccount, binding: ProjectIntegrationBinding} $setup */
function runSync(array $setup): SyncOutcome
{
    return app(SyncBoundAccount::class)(
        (string) $setup['scenario']->workspace->getKey(),
        (string) $setup['account']->getKey(),
    );
}

describe('isolation', function (): void {
    it('refuses to sync an account no project was given', function (): void {
        $setup = syncScenario(syncEnabled: false);

        $outcome = runSync($setup);

        /*
         * Bound but paused. The isolation model is about data as much as about
         * reads: pausing sync is how an operator says "stop pulling this
         * client's numbers", and it has to actually stop.
         */
        expect($outcome->status)->toBe(SyncStatus::Skipped)
            ->and(Campaign::query()->count())->toBe(0)
            ->and(DailyMetric::query()->count())->toBe(0);
    });

    it('refuses to sync an account belonging to another workspace', function (): void {
        $setup = syncScenario();
        $other = WorkspaceScenario::create();

        $outcome = app(SyncBoundAccount::class)(
            (string) $other->workspace->getKey(),
            (string) $setup['account']->getKey(),
        );

        // Not an error, and no hint that the account exists elsewhere.
        expect($outcome->status)->toBe(SyncStatus::Skipped)
            ->and(Campaign::query()->count())->toBe(0);
    });

    it('scopes every written row to the workspace that synced it', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $workspaceId = (string) $setup['scenario']->workspace->getKey();

        expect(Campaign::query()->where('workspace_id', '!=', $workspaceId)->count())->toBe(0)
            ->and(AdGroup::query()->where('workspace_id', '!=', $workspaceId)->count())->toBe(0)
            ->and(Ad::query()->where('workspace_id', '!=', $workspaceId)->count())->toBe(0)
            ->and(DailyMetric::query()->where('workspace_id', '!=', $workspaceId)->count())->toBe(0);
    });
});

describe('structure', function (): void {
    it('syncs the whole hierarchy and links it together', function (): void {
        $setup = syncScenario();

        $outcome = runSync($setup);

        expect($outcome->status)->toBe(SyncStatus::Succeeded)
            ->and(Campaign::query()->count())->toBe(4)
            ->and(AdGroup::query()->count())->toBe(5)
            ->and(Creative::query()->count())->toBe(7)
            ->and(Ad::query()->count())->toBe(7);

        $ad = Ad::query()->where('external_id', '23850000002000001')->firstOrFail();

        // Every ad knows its ad group, its campaign and its creative. Without
        // all three, drill-down and creative reporting have nothing to join on.
        expect($ad->adGroup)->not->toBeNull()
            ->and($ad->campaign)->not->toBeNull()
            ->and($ad->creative)->not->toBeNull();

        expect($ad->adGroup?->external_id)->toBe('23850000001000001')
            ->and($ad->campaign?->external_id)->toBe('23850000000000001')
            ->and($ad->creative?->external_id)->toBe('23850000003000001');
    });

    it('pages through everything rather than stopping at the first page', function (): void {
        $setup = syncScenario();

        // The sandbox pages two at a time, so four campaigns means three pages.
        runSync($setup);

        expect(Campaign::query()->count())->toBe(4);
    });

    it('keeps the provider status words alongside the normalised one', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $campaign = Campaign::query()->where('external_id', '23850000000000002')->firstOrFail();

        expect($campaign->status)->toBe(EntityStatus::NotDelivering)
            ->and($campaign->raw_status)->toBe('ACTIVE')
            ->and($campaign->raw_effective_status)->toBe('WITH_ISSUES');
    });

    it('stores a carousel’s cards in order', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $creative = Creative::query()->where('external_id', '23850000003000001')->firstOrFail();

        expect($creative->assets)->toHaveCount(3)
            ->and($creative->assets->pluck('position')->all())->toBe([0, 1, 2]);
    });

    it('replaces a creative’s assets instead of accumulating them', function (): void {
        $setup = syncScenario();
        runSync($setup);
        $before = CreativeAsset::query()->count();

        runSync($setup);

        // A re-sync must not double a carousel's cards.
        expect(CreativeAsset::query()->count())->toBe($before);
    });
});

describe('performance', function (): void {
    it('writes daily metrics at every level the provider reports separately', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $levels = DailyMetric::query()
            ->selectRaw('entity_type, count(*) as rows')
            ->groupBy('entity_type')
            ->pluck('rows', 'entity_type')
            ->all();

        /*
         * Levels are stored, not derived. A campaign's reach is deduplicated
         * across its ads, so summing the ad rows would produce a number Meta
         * would not agree with.
         */
        expect($levels)->toHaveKeys(['campaign', 'ad_group', 'ad']);
    });

    it('attaches each metric row to its whole ancestry', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $adRow = DailyMetric::query()->where('entity_type', 'ad')->firstOrFail();

        expect($adRow->ad_id)->not->toBeNull()
            ->and($adRow->ad_group_id)->not->toBeNull()
            // Denormalised ancestry is what lets a campaign filter run without
            // joining three tables on the largest table in the system.
            ->and($adRow->campaign_id)->not->toBeNull();
    });

    it('keeps a metric the provider did not report as null, not zero', function (): void {
        $setup = syncScenario();
        runSync($setup);

        // Non-video ads have no completion figure at all.
        $missing = DailyMetric::query()
            ->where('entity_type', 'ad')
            ->whereNull('video_completions')
            ->count();

        expect($missing)->toBeGreaterThan(0);
    });

    it('breaks conversions out by action type with their own values', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $purchase = ConversionMetric::query()->where('raw_action_type', 'purchase')->firstOrFail();

        expect($purchase->count)->toBeGreaterThan(0)
            ->and($purchase->value_minor)->toBeGreaterThan(0)
            ->and($purchase->currency)->toBe('SAR')
            ->and(ConversionMetric::query()->where('raw_action_type', 'add_to_cart')->exists())->toBeTrue();
    });

    it('never records a campaign-level reach above its impressions', function (): void {
        $setup = syncScenario();
        runSync($setup);

        // Enforced by a CHECK constraint too; asserted here because the
        // constraint would only fail the insert, and a silent skip would hide it.
        $impossible = DailyMetric::query()
            ->whereNotNull('reach')
            ->whereNotNull('impressions')
            ->whereColumn('reach', '>', 'impressions')
            ->count();

        expect($impossible)->toBe(0);
    });
});

describe('data honesty', function (): void {
    it('stamps every sandbox row as sandbox', function (): void {
        $setup = syncScenario();
        runSync($setup);

        /** @var list<MetricSource> $sources */
        $sources = DailyMetric::query()->distinct()->pluck('source')->all();

        /*
         * The whole point of the fixtures. A sandbox number that reached a
         * client labelled "live" is the one failure this project treats as
         * unrecoverable, so the label is written with the row and there is no
         * path that can relabel it later.
         */
        expect($sources)->toBe([MetricSource::Sandbox])
            ->and(ConversionMetric::query()->distinct()->pluck('source')->all())
            ->toBe([MetricSource::Sandbox]);
    });

    it('records the source on the sync run as well as the rows', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $run = SyncRun::query()->where('kind', SyncKind::Insights->value)->firstOrFail();
        $metadata = $run->metadata;

        // So a run stays identifiable as sandbox even after the connection is
        // switched to real credentials.
        expect(is_array($metadata) ? $metadata['source'] ?? null : null)
            ->toBe(MetricSource::Sandbox->value);
    });

    it('refuses outright when credentials are real rather than inventing data', function (): void {
        $setup = syncScenario();

        storeCredentials($setup['scenario'], [
            'client_id' => 'a-real-looking-id',
            'client_secret' => 'a-real-looking-secret',
        ]);

        $outcome = runSync($setup);

        /*
         * BLOCKED_EXTERNAL, made executable. No Meta app has been approved, so
         * there is nothing to call — and the one thing that must never happen
         * here is a plausible number appearing anyway.
         */
        expect($outcome->status)->toBe(SyncStatus::Failed)
            ->and($outcome->failureMessage)->toContain('has not been granted API access')
            ->and(DailyMetric::query()->count())->toBe(0)
            ->and(Campaign::query()->count())->toBe(0);
    });

    it('records the refusal as an auth error against the run', function (): void {
        $setup = syncScenario();

        storeCredentials($setup['scenario'], ['client_id' => 'real', 'client_secret' => 'real']);

        runSync($setup);

        $run = SyncRun::query()->where('kind', SyncKind::Campaigns->value)->firstOrFail();
        $error = $run->errors()->first();

        expect($run->status)->toBe(SyncStatus::Failed)
            ->and($run->errors()->count())->toBe(1)
            ->and($error?->category)->toBe(ProviderErrorCategory::Auth);
    });
});

describe('idempotency', function (): void {
    it('produces the same database when run twice', function (): void {
        $setup = syncScenario();

        runSync($setup);
        $counts = [
            'campaigns' => Campaign::query()->count(),
            'ad_groups' => AdGroup::query()->count(),
            'ads' => Ad::query()->count(),
            'creatives' => Creative::query()->count(),
            'daily_metrics' => DailyMetric::query()->count(),
            'conversions' => ConversionMetric::query()->count(),
        ];

        runSync($setup);

        /*
         * Not a nicety. Jobs retry, ranges overlap deliberately because
         * attribution keeps moving, and a sync that double-counted spend would
         * put a wrong number on a client's invoice.
         */
        expect(Campaign::query()->count())->toBe($counts['campaigns'])
            ->and(AdGroup::query()->count())->toBe($counts['ad_groups'])
            ->and(Ad::query()->count())->toBe($counts['ads'])
            ->and(Creative::query()->count())->toBe($counts['creatives'])
            ->and(DailyMetric::query()->count())->toBe($counts['daily_metrics'])
            ->and(ConversionMetric::query()->count())->toBe($counts['conversions']);
    });

    it('updates a changed figure in place rather than adding a row', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $row = DailyMetric::query()->where('entity_type', 'ad')->firstOrFail();
        $realSpend = $row->spend_minor;
        $row->update(['spend_minor' => 1]);

        runSync($setup);

        $reread = DailyMetric::query()->whereKey($row->getKey())->firstOrFail();

        // The provider is the authority on its own numbers, and re-reading a day
        // has to be able to correct it.
        expect($reread->spend_minor)->toBe($realSpend);
    });

    it('tracks what it synced and how far through', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $cursor = SyncCursor::query()
            ->where('kind', SyncKind::Insights->value)
            ->firstOrFail();

        $syncedThrough = $cursor->synced_through;
        $resyncFrom = $cursor->resync_from;

        expect($syncedThrough)->not->toBeNull()
            // The next run deliberately re-reads this far back, because
            // conversions keep landing against dates already synced.
            ->and($resyncFrom)->not->toBeNull();

        expect($resyncFrom < $syncedThrough)->toBeTrue()
            ->and($cursor->consecutive_failures)->toBe(0);
    });

    it('leaves the structure cursor empty so the next run re-reads everything', function (): void {
        $setup = syncScenario();
        runSync($setup);

        $cursor = SyncCursor::query()->where('kind', SyncKind::Campaigns->value)->firstOrFail();

        // Entities get renamed, paused and deleted. A retained cursor would make
        // us blind to every campaign before it.
        expect($cursor->cursor)->toBeNull()
            ->and($cursor->last_success_at)->not->toBeNull();
    });
});

describe('the journal', function (): void {
    it('records a run per kind, with counts', function (): void {
        $setup = syncScenario();
        $outcome = runSync($setup);

        /** @var array<string, SyncStatus> $runs */
        $runs = SyncRun::query()->pluck('status', 'kind')->all();

        expect($runs)->toHaveKeys(['campaigns', 'ad_groups', 'creatives', 'ads', 'insights'])
            ->and(array_unique(array_map(
                static fn (SyncStatus $status): string => $status->value,
                array_values($runs),
            )))->toBe([SyncStatus::Succeeded->value])
            ->and($outcome->recordsWritten)->toBeGreaterThan(0)
            ->and(SyncRun::query()->sum('records_written'))->toBe($outcome->recordsWritten);
    });

    it('counts a consecutive failure rather than overwriting the last one', function (): void {
        $setup = syncScenario();

        storeCredentials($setup['scenario'], ['client_id' => 'real', 'client_secret' => 'real']);

        runSync($setup);
        runSync($setup);

        $cursor = SyncCursor::query()->where('kind', SyncKind::Campaigns->value)->firstOrFail();

        // Failing for the second time running is a different problem from
        // failing once, and the number is what makes that visible.
        expect($cursor->consecutive_failures)->toBe(2);
    });
});

describe('scheduling', function (): void {
    it('queues a job only for accounts a project actually syncs', function (): void {
        Queue::fake();

        syncScenario();
        syncScenario(syncEnabled: false, externalId: 'act_sandbox_1002');

        $this->artisan('advertising:sync')->assertSuccessful();

        Queue::assertPushed(SyncBoundAccountJob::class, 1);
    });

    it('can be limited to one workspace', function (): void {
        Queue::fake();

        $first = syncScenario();
        syncScenario(externalId: 'act_sandbox_1003');

        $this->artisan('advertising:sync', [
            '--workspace' => (string) $first['scenario']->workspace->getKey(),
        ])->assertSuccessful();

        Queue::assertPushed(SyncBoundAccountJob::class, 1);
    });

    it('says plainly when there is nothing bound to sync', function (): void {
        Queue::fake();

        WorkspaceScenario::create();

        $this->artisan('advertising:sync')
            ->expectsOutputToContain('No bound accounts have sync enabled.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('is unique per account, so two syncs cannot race on the same upserts', function (): void {
        $setup = syncScenario();

        $job = new SyncBoundAccountJob(
            (string) $setup['scenario']->workspace->getKey(),
            (string) $setup['account']->getKey(),
        );

        expect($job->uniqueId())->toBe(
            $setup['scenario']->workspace->getKey().':'.$setup['account']->getKey(),
        );
    });
});
