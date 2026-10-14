<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A synthetic agency, at the size the product is meant to survive.
 *
 * Separate from `DemoWorkspaceSeeder` on purpose, and the separation is the point of rule 105. The demo
 * seeder builds a small, coherent, hand-checked agency whose every figure is explicable — that is what
 * makes it useful for reading screens and useless as performance evidence. A dashboard that is instant
 * over three clients tells you nothing about a dashboard over a hundred, and the gap is where agency-scale
 * products quietly stop working.
 *
 * Nothing here is realistic in its *content*. Campaign names are generated, spend is a seeded pseudo-random
 * walk, and no figure should ever be read as a number about anything. What is realistic is the **shape**:
 * the fan-out from client to project to account to campaign, accounts bound to more than one project, a
 * long tail of small clients beside a few large ones, and a daily fact table large enough that an
 * unindexed scan shows up.
 *
 * ```
 * php artisan db:seed --class=Database\Seeders\AgencyScaleSeeder
 * ```
 *
 * Scale comes from `config/agency_scale.php`, so a test can build a smaller but identically *shaped*
 * agency with `config([...])` — and can build two sizes inside one process, which is what the
 * scale-invariance assertions need.
 */
final class AgencyScaleSeeder extends Seeder
{
    public const string WORKSPACE_SLUG = 'agency-scale';

    public const string OWNER_EMAIL = 'scale-owner@digitalsmart.test';

    /** Providers the fixture spreads accounts across. Values the check constraints already accept. */
    private const array PROVIDERS = ['meta', 'google_ads', 'tiktok', 'snapchat', 'linkedin'];

    private const array CURRENCIES = ['SAR', 'SAR', 'SAR', 'AED', 'USD'];

    /** Insert width that keeps Postgres' 65535-parameter limit out of reach. */
    private const int CHUNK = 500;

    private int $seed = 20_260_415;

    public function __construct(
        private readonly WorkspaceProvisioner $provisioner,
        private readonly IdentifierGenerator $ids,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Refusing to seed scale data in production.');

            return;
        }

        $clientCount = $this->scale('clients', 100);
        $projectsPerClient = $this->scale('projects_per_client', 2);
        $accountCount = $this->scale('accounts', 300);
        $campaignCount = $this->scale('campaigns', 2_000);
        $days = $this->scale('days', 90);

        $this->provisioner->syncPermissionCatalogue();

        /*
         * Rebuilt from scratch every run, and only ever its own workspace.
         *
         * The alternative — upserting into whatever is already there — makes the fixture a function of
         * how many times it has been run, and a performance measurement that cannot be compared with the
         * one before it is not a measurement. The delete is scoped to the reserved slug and the whole
         * seeder refuses to run in production; every table below it goes by cascade.
         */
        DB::table('workspaces')->where('slug', self::WORKSPACE_SLUG)->delete();

        $owner = User::query()->firstOrNew(['email' => self::OWNER_EMAIL]);

        /* `forceFill`, because the model guards everything but name, email and password. */
        $owner->forceFill([
            'name' => 'Scale Owner',
            'password' => Hash::make('scale-owner-password'),
            'email_verified_at' => now(),
            'locale' => 'en',
            'timezone' => 'Asia/Riyadh',
            'is_active' => true,
        ])->save();

        $workspace = $this->provisioner->provision(
            name: 'SCALE — Synthetic Agency',
            slug: self::WORKSPACE_SLUG,
            owner: $owner,
            defaultCurrency: 'SAR',
            timezone: 'Asia/Riyadh',
        );

        $roles = $this->provisioner->createSystemRoles($workspace);
        $workspaceId = (string) $workspace->getKey();

        WorkspaceMember::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'user_id' => $owner->getKey()],
            [
                'role_id' => $roles[SystemRole::Owner->value]->getKey(),
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ],
        );

        $clients = $this->clients($workspaceId, $clientCount);
        $projects = $this->projects($workspaceId, $clients, $projectsPerClient);
        $connections = $this->connections($workspaceId);
        $accounts = $this->accounts($workspaceId, $connections, $accountCount);
        $this->bindings($workspaceId, $projects, $accounts);
        $campaigns = $this->campaigns($workspaceId, $accounts, $campaignCount);
        $rows = $this->metrics($workspaceId, $campaigns, $days);

        $this->command?->info(sprintf(
            'Seeded %d clients, %d projects, %d accounts, %d campaigns, %d daily rows.',
            count($clients),
            count($projects),
            count($accounts),
            count($campaigns),
            $rows,
        ));
    }

    /** @return list<string> */
    private function clients(string $workspaceId, int $count): array
    {
        $rows = [];
        $ids = [];
        $now = now();

        for ($index = 1; $index <= $count; $index++) {
            $id = $this->ids->generate();
            $ids[] = $id;
            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'name' => sprintf('Scale Client %03d', $index),
                'slug' => sprintf('scale-client-%03d', $index),
                'status' => $index % 11 === 0 ? 'paused' : 'active',
                'industry' => ['Retail', 'Food', 'Property', 'Health', 'Education'][$index % 5],
                'default_currency' => self::CURRENCIES[$index % 5],
                /*
                 * Flagged as demo data, like everything else synthetic in this installation. A figure from
                 * this workspace must never be mistaken for a figure about a client being billed.
                 */
                'is_demo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insert('clients', $rows);

        return $ids;
    }

    /**
     * @param  list<string>  $clients
     * @return list<array{id: string, client_id: string}>
     */
    private function projects(string $workspaceId, array $clients, int $perClient): array
    {
        $rows = [];
        $projects = [];
        $now = now();
        $sequence = 0;

        foreach ($clients as $clientIndex => $clientId) {
            /*
             * A long tail, not a uniform grid. Most clients have one or two projects and a handful carry
             * several; a flat fan-out hides the query shapes that only appear when one client is large.
             */
            $count = $clientIndex % 10 === 0 ? $perClient + 3 : $perClient;

            for ($index = 1; $index <= $count; $index++) {
                $sequence++;
                $id = $this->ids->generate();
                $projects[] = ['id' => $id, 'client_id' => $clientId];
                $rows[] = [
                    'id' => $id,
                    'workspace_id' => $workspaceId,
                    'client_id' => $clientId,
                    'name' => sprintf('Scale Project %04d', $sequence),
                    'slug' => sprintf('scale-project-%04d', $sequence),
                    'status' => $sequence % 13 === 0 ? 'paused' : 'active',
                    'currency' => 'SAR',
                    'is_demo' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $this->insert('projects', $rows);

        return $projects;
    }

    /** @return array<string, string> provider => connection id */
    private function connections(string $workspaceId): array
    {
        $rows = [];
        $connections = [];
        $now = now();

        foreach (self::PROVIDERS as $provider) {
            $id = $this->ids->generate();
            $connections[$provider] = $id;
            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'provider' => $provider,
                /* One agency connection per platform, serving every account under it — rule 17's shape. */
                'owner_type' => 'agency',
                'owner_client_id' => null,
                'name' => ucfirst(str_replace('_', ' ', $provider)).' — Agency',
                'status' => 'connected',
                'credentials' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insert('provider_connections', $rows);

        return $connections;
    }

    /**
     * @param  array<string, string>  $connections
     * @return list<array{id: string, provider: string, currency: string}>
     */
    private function accounts(string $workspaceId, array $connections, int $count): array
    {
        $rows = [];
        $accounts = [];
        $now = now();

        for ($index = 1; $index <= $count; $index++) {
            $provider = self::PROVIDERS[$index % count(self::PROVIDERS)];
            $currency = self::CURRENCIES[$index % count(self::CURRENCIES)];
            $id = $this->ids->generate();
            $accounts[] = ['id' => $id, 'provider' => $provider, 'currency' => $currency];
            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'provider_connection_id' => $connections[$provider],
                'provider' => $provider,
                'external_id' => sprintf('%s-%06d', $provider, $index),
                'name' => sprintf('Scale Account %04d', $index),
                'currency' => $currency,
                'timezone' => 'Asia/Riyadh',
                'status' => $index % 17 === 0 ? 'disabled' : 'active',
                'metadata' => '{}',
                'discovered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insert('external_accounts', $rows);

        return $accounts;
    }

    /**
     * Bind most accounts to one project and some to several.
     *
     * The multi-project accounts are the ones that matter: they are the shape rule 7 permits and rule 8
     * had no answer for, and every campaign under them is ambiguous until somebody assigns it. A fixture
     * without them would make the ownership queue look permanently empty.
     *
     * One account in every twenty is left bound to nothing, so the `unbound` state has a population too.
     *
     * @param  list<array{id: string, client_id: string}>  $projects
     * @param  list<array{id: string, provider: string, currency: string}>  $accounts
     */
    private function bindings(string $workspaceId, array $projects, array $accounts): void
    {
        $rows = [];
        $now = now();
        $projectCount = count($projects);

        foreach ($accounts as $index => $account) {
            if ($index % 20 === 19) {
                continue;
            }

            $shared = $index % 7 === 0 ? 3 : 1;

            for ($offset = 0; $offset < $shared; $offset++) {
                $project = $projects[($index * 3 + $offset) % $projectCount];

                $rows[] = [
                    'id' => $this->ids->generate(),
                    'workspace_id' => $workspaceId,
                    'project_id' => $project['id'],
                    'external_account_id' => $account['id'],
                    'sync_enabled' => true,
                    'bound_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        /* The index arithmetic above can land on the same pair twice, and the pair is unique. */
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('project_integration_bindings')
                ->upsert($chunk, ['project_id', 'external_account_id'], ['sync_enabled']);
        }
    }

    /**
     * @param  list<array{id: string, provider: string, currency: string}>  $accounts
     * @return list<array{id: string, account_id: string, provider: string, currency: string}>
     */
    private function campaigns(string $workspaceId, array $accounts, int $count): array
    {
        $rows = [];
        $campaigns = [];
        $now = now();
        $accountCount = count($accounts);
        $objectives = ['sales', 'leads', 'traffic', 'awareness', 'video_views'];
        $statuses = ['active', 'active', 'active', 'paused', 'completed'];

        for ($index = 1; $index <= $count; $index++) {
            $account = $accounts[$index % $accountCount];
            $id = $this->ids->generate();
            $campaigns[] = [
                'id' => $id,
                'account_id' => $account['id'],
                'provider' => $account['provider'],
                'currency' => $account['currency'],
            ];

            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'external_account_id' => $account['id'],
                /* Never pre-assigned: the ownership queue is only honest if the fixture leaves it to be done. */
                'project_id' => null,
                'provider' => $account['provider'],
                'external_id' => sprintf('camp-%07d', $index),
                'name' => sprintf('Scale Campaign %05d', $index),
                'objective' => $objectives[$index % 5],
                'status' => $statuses[$index % 5],
                'budget_mode' => 'daily',
                'budget_minor' => 50_000 + ($index % 40) * 2_500,
                'currency' => $account['currency'],
                'metadata' => '{}',
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insert('campaigns', $rows);

        return $campaigns;
    }

    /**
     * One row per campaign per day.
     *
     * Campaign level only. Account, ad-group and ad rows would multiply this by an order of magnitude for
     * no extra coverage of the queries under test: every agency-level read aggregates campaign rows, and a
     * fixture that takes ten minutes to build is a fixture nobody runs.
     *
     * @param  list<array{id: string, account_id: string, provider: string, currency: string}>  $campaigns
     */
    private function metrics(string $workspaceId, array $campaigns, int $days): int
    {
        $today = new DateTimeImmutable('today');
        $rows = [];
        $written = 0;

        foreach ($campaigns as $campaign) {
            for ($back = 0; $back < $days; $back++) {
                $date = $today->modify(sprintf('-%d days', $back));
                $impressions = 2_000 + $this->noise(60_000);
                $clicks = (int) round($impressions * (0.004 + $this->noise(100) / 10_000));

                $rows[] = [
                    'id' => $this->ids->generate(),
                    'workspace_id' => $workspaceId,
                    'external_account_id' => $campaign['account_id'],
                    'provider' => $campaign['provider'],
                    'entity_type' => 'campaign',
                    'entity_id' => $campaign['id'],
                    'external_entity_id' => $campaign['id'],
                    'campaign_id' => $campaign['id'],
                    'date' => $date->format('Y-m-d'),
                    'account_timezone' => 'Asia/Riyadh',
                    'currency' => $campaign['currency'],
                    'spend_minor' => 10_000 + $this->noise(400_000),
                    'revenue_minor' => 15_000 + $this->noise(1_200_000),
                    'impressions' => $impressions,
                    /* Reach never exceeds impressions — the table has a check constraint saying so. */
                    'reach' => (int) round($impressions * 0.7),
                    'clicks' => $clicks,
                    'conversions' => round($clicks * 0.03, 4),
                    'source' => 'sandbox',
                    'metadata' => '{}',
                    'synced_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($rows) >= self::CHUNK) {
                    $written += $this->insertMetrics($rows);
                    $rows = [];
                }
            }
        }

        return $written + $this->insertMetrics($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertMetrics(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::table('daily_metrics')->insert($rows);

        return count($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * Deterministic pseudo-randomness.
     *
     * A seeded linear congruential step rather than `random_int`, so two runs produce the same fixture and
     * a performance measurement can be compared with the one before it. A fixture that changes between
     * runs makes every regression arguable.
     */
    private function noise(int $bound): int
    {
        $this->seed = ($this->seed * 1_103_515_245 + 12_345) & 0x7FFFFFFF;

        return $this->seed % $bound;
    }

    private function scale(string $key, int $default): int
    {
        $value = config('agency_scale.'.$key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
