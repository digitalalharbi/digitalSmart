<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A workspace with real synced data in it.
 *
 * Analytics tests need the fact table populated, and populating it by hand would
 * mean asserting against figures nothing produced. Running the real sync over the
 * SANDBOX fixtures means the numbers under test are the numbers the pipeline
 * actually writes — including the nulls.
 */
final class SyncedWorkspace
{
    /** @var array<string, ExternalAccount> keyed by provider */
    public array $accounts = [];

    /** @var array<string, Project> keyed by provider */
    public array $projects = [];

    private function __construct(public readonly WorkspaceScenario $scenario) {}

    /**
     * Connect a provider in sandbox mode, bind it to its own project, and sync.
     *
     * @param  array<string, string>  $externalIds  provider value → account external id
     */
    public static function with(array $externalIds): self
    {
        $instance = new self(WorkspaceScenario::create());

        foreach ($externalIds as $providerValue => $externalId) {
            $instance->connect(Provider::from($providerValue), $externalId);
        }

        $instance->sync();

        return $instance;
    }

    /** The default: Meta and Google, one project each. */
    public static function default(): self
    {
        return self::with([
            Provider::Meta->value => 'act_sandbox_1001',
            Provider::GoogleAds->value => '7788990011',
        ]);
    }

    public function workspaceId(): string
    {
        return (string) $this->scenario->workspace->getKey();
    }

    public function account(Provider $provider): ExternalAccount
    {
        return $this->accounts[$provider->value];
    }

    public function project(Provider $provider): Project
    {
        return $this->projects[$provider->value];
    }

    /**
     * Make sure last calendar month has figures in it.
     *
     * The sandbox fixtures are fourteen hand-authored days, always the fourteen ending yesterday. A test that
     * asks for "last calendar month" therefore passes during the first fortnight of a month — when those
     * days straddle the boundary — and fails for the rest of it, because the fixture has nothing from the
     * month it is asked about. Nothing was wrong with the product; the suite's result depended on the date.
     *
     * So when the fixture does not already reach last month, a copy of it is laid down ending on that
     * month's last day. A copy rather than a move: windows that read the recent fortnight keep theirs.
     */
    public function ensureLastMonthCovered(): void
    {
        $lastDay = (new DateTimeImmutable('last day of last month'))->format('Y-m-d');

        $earliest = DB::table('daily_metrics')->min('date');

        if ($earliest === null || $earliest <= $lastDay) {
            return;
        }

        $shift = (int) (new DateTimeImmutable((string) DB::table('daily_metrics')->max('date')))
            ->diff(new DateTimeImmutable($lastDay))->days;

        DB::statement(
            'insert into daily_metrics (id, workspace_id, external_account_id, provider, entity_type, entity_id,
                external_entity_id, campaign_id, ad_group_id, ad_id, date, account_timezone, currency, spend_minor,
                revenue_minor, impressions, reach, clicks, link_clicks, video_views, video_completions, engagements,
                conversions, source, metadata, synced_at, created_at, updated_at)
             select gen_random_uuid(), workspace_id, external_account_id, provider, entity_type, entity_id,
                external_entity_id, campaign_id, ad_group_id, ad_id, date - ?::int, account_timezone, currency,
                spend_minor, revenue_minor, impressions, reach, clicks, link_clicks, video_views, video_completions,
                engagements, conversions, source, metadata, synced_at, created_at, updated_at
             from daily_metrics where date - ?::int <= ?::date',
            [$shift, $shift, $lastDay],
        );
    }

    private function connect(Provider $provider, string $externalId): void
    {
        $repository = app(ProviderConnectionRepository::class);

        // Every required credential must be the token: a half-filled connection
        // is a configuration mistake, not a sandbox.
        $credentials = [];

        foreach ($provider->requiredCredentialKeys() as $key) {
            $credentials[$key] = Credentials::SANDBOX_TOKEN;
        }

        $connection = ProviderConnection::register(
            id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
            workspaceId: new WorkspaceId($this->workspaceId()),
            provider: $provider,
            owner: ConnectionOwner::agency(),
            name: $provider->label(),
            credentials: new Credentials($credentials),
        );
        $repository->save($connection);

        $account = ExternalAccount::query()->create([
            'workspace_id' => $this->scenario->workspace->getKey(),
            'provider_connection_id' => $connection->id->value,
            'provider' => $provider->value,
            'external_id' => $externalId,
            'name' => 'SANDBOX — '.$provider->label(),
            'currency' => 'SAR',
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
            'discovered_at' => now(),
        ]);

        $client = Client::factory()->create(['workspace_id' => $this->scenario->workspace->getKey()]);
        $project = Project::factory()->forClient($client)->create([
            'name' => $provider->label().' project',
        ]);

        ProjectIntegrationBinding::query()->create([
            'workspace_id' => $this->scenario->workspace->getKey(),
            'project_id' => $project->getKey(),
            'external_account_id' => $account->getKey(),
            'sync_enabled' => true,
            'bound_at' => now(),
        ]);

        $this->accounts[$provider->value] = $account;
        $this->projects[$provider->value] = $project;
    }

    private function sync(): void
    {
        $sync = app(SyncBoundAccount::class);

        foreach ($this->accounts as $account) {
            $sync($this->workspaceId(), (string) $account->getKey());
        }
    }
}
