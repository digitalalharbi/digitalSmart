<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Portal\Command\EnablePortal;
use App\Application\Portal\Command\SetPortalModules;
use App\Application\Reporting\Command\SaveReport;
use App\Application\Reporting\Command\TakeSnapshot;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\SectionDefinition;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Identity\UserId;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Portal\PortalModule;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * An agency that looks like one.
 *
 * Built on top of `DemoWorkspaceSeeder`'s workspace, so the five demo sign-ins and everything the end-to-end
 * suite relies on are left exactly as they were, and this adds the population around them: about sixty
 * clients with plausible names, several projects each, ad accounts across every platform, campaigns with
 * *shaped* time series (ramps, fatigue, a collapse, a recovery, a payday spike, a quiet weekend), budgets sized
 * to produce honest risks, leads that follow the campaigns that generated them, store orders, tasks, creative
 * requests, approvals, client requests and notifications.
 *
 * **Every number here is SANDBOX.** Daily rows carry `source = 'sandbox'`, clients and projects are flagged
 * `is_demo`, and nothing is written as `live`. The names are invented and chosen to read like a Gulf agency's
 * book; any resemblance to a real business is accidental.
 *
 * **Deterministic.** One seeded generator and no clock-dependence beyond "today", so two runs produce the same
 * agency and an end-to-end test can rely on it. Re-running replaces what this seeder created and nothing else.
 *
 * ```
 * php artisan demo:agency
 * ```
 */
final class DemoAgencySeeder extends Seeder
{
    public const string WORKSPACE_SLUG = 'demo-smart-code';

    /** Everything this seeder creates carries this prefix in its slug or external id, so it can be replaced. */
    public const string TAG = 'agd-';

    private const int CHUNK = 500;

    private const int DAYS = 60;

    /** @var list<string> */
    private const array BRANDS = [
        'Noor', 'Safwa', 'Kayan', 'Tamkeen', 'Wahat', 'Najma', 'Rimal', 'Daleel', 'Awj', 'Sahab',
        'Riyada', 'Manara', 'Bayan', 'Zaha', 'Jood', 'Hayat', 'Diyar', 'Ufuq', 'Sanad', 'Nakheel',
    ];

    /**
     * Industry → business nouns, the objectives its campaigns lean on, and the programmes it runs.
     *
     * @var array<string, array{nouns: list<string>, objectives: list<string>, commerce: bool, programmes: list<string>}>
     */
    private const array INDUSTRIES = [
        'Fashion & Retail' => [
            'nouns' => ['Fashion House', 'Boutique', 'Abayas', 'Footwear'],
            'objectives' => ['sales', 'sales', 'awareness', 'traffic'],
            'commerce' => true,
            'programmes' => ['Always-on Performance', 'White Friday', 'Ramadan Collection', 'Summer Sale'],
        ],
        'Real Estate' => [
            'nouns' => ['Properties', 'Developments', 'Real Estate'],
            'objectives' => ['leads', 'leads', 'awareness', 'video_views'],
            'commerce' => false,
            'programmes' => ['Lead Generation — Riyadh', 'Project Launch', 'Always-on Leads'],
        ],
        'Healthcare' => [
            'nouns' => ['Dental Care', 'Clinics', 'Medical Center', 'Pharmacy'],
            'objectives' => ['leads', 'leads', 'awareness', 'messages'],
            'commerce' => false,
            'programmes' => ['Appointments Drive', 'Always-on Performance', 'Seasonal Check-up'],
        ],
        'Food & Beverage' => [
            'nouns' => ['Coffee Roasters', 'Kitchen', 'Bakery', 'Restaurants'],
            'objectives' => ['sales', 'traffic', 'awareness', 'video_views'],
            'commerce' => true,
            'programmes' => ['Always-on Performance', 'Ramadan Offers', 'National Day Menu'],
        ],
        'Automotive' => [
            'nouns' => ['Motors', 'Auto Group', 'Car Rental'],
            'objectives' => ['leads', 'awareness', 'traffic', 'video_views'],
            'commerce' => false,
            'programmes' => ['Test-drive Leads', 'Model Launch', 'Always-on Performance'],
        ],
        'Education' => [
            'nouns' => ['Academy', 'Institute', 'Language School'],
            'objectives' => ['leads', 'leads', 'traffic', 'awareness'],
            'commerce' => false,
            'programmes' => ['Enrolment Drive', 'Back to School', 'Always-on Performance'],
        ],
        'Electronics' => [
            'nouns' => ['Electronics', 'Gadgets', 'Home Appliances'],
            'objectives' => ['sales', 'sales', 'traffic', 'awareness'],
            'commerce' => true,
            'programmes' => ['Always-on Performance', 'White Friday', 'Founding Day Offer'],
        ],
        'Travel & Leisure' => [
            'nouns' => ['Travel', 'Resorts', 'Tours'],
            'objectives' => ['sales', 'traffic', 'video_views', 'awareness'],
            'commerce' => false,
            'programmes' => ['Summer Getaways', 'Always-on Performance', 'Umrah Season'],
        ],
        'Financial Services' => [
            'nouns' => ['Capital', 'Finance', 'Pay'],
            'objectives' => ['leads', 'app_promotion', 'awareness', 'traffic'],
            'commerce' => false,
            'programmes' => ['Account Opening', 'App Launch', 'Always-on Performance'],
        ],
    ];

    /** Platform → [cost per thousand impressions in SAR, click-through rate]. */
    private const array PLATFORMS = [
        'meta' => ['cpm' => 18.0, 'ctr' => 0.012],
        'google_ads' => ['cpm' => 26.0, 'ctr' => 0.028],
        'tiktok' => ['cpm' => 12.0, 'ctr' => 0.009],
        'snapchat' => ['cpm' => 14.0, 'ctr' => 0.008],
        'x' => ['cpm' => 20.0, 'ctr' => 0.006],
        'linkedin' => ['cpm' => 62.0, 'ctr' => 0.007],
        'openai' => ['cpm' => 32.0, 'ctr' => 0.02],
    ];

    private const array PATTERNS = [
        'steady', 'growth', 'steady', 'decline', 'steady', 'launch', 'fatigue', 'steady',
        'recovery', 'steady', 'overspend', 'steady', 'tracking', 'steady', 'steady',
    ];

    /** @var list<string> */
    private const array FIRST = ['Abdullah', 'Faisal', 'Sultan', 'Nora', 'Reem', 'Lama', 'Mohammed', 'Sara', 'Khalid', 'Hessa', 'Turki', 'Maha', 'Yousef', 'Dana', 'Saad', 'Aisha', 'Majed', 'Hind', 'Bader', 'Rana'];

    /** @var list<string> */
    private const array LAST = ['Al-Qahtani', 'Al-Otaibi', 'Al-Shehri', 'Al-Harbi', 'Al-Ghamdi', 'Al-Dossari', 'Al-Zahrani', 'Al-Mutairi', 'Al-Anazi', 'Al-Subaie', 'Bin Saeed', 'Al-Rashid'];

    private int $seed = 20_261_016;

    public function __construct(private readonly IdentifierGenerator $ids) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Refusing to seed demo data in production.');

            return;
        }

        $workspaceId = DB::table('workspaces')->where('slug', self::WORKSPACE_SLUG)->value('id');

        if (! is_string($workspaceId)) {
            $this->command?->error('Run the demo workspace seeder first (demo:agency does).');

            return;
        }

        DB::transaction(function () use ($workspaceId): void {
            $this->clear($workspaceId);

            $people = $this->people($workspaceId);
            $clients = $this->clients($workspaceId, $people['owner']);
            $projects = $this->projects($workspaceId, $clients);
            $this->members($projects, $people);

            $connections = $this->connections($workspaceId);
            $accounts = $this->accounts($workspaceId, $connections, $clients);
            $this->bindings($workspaceId, $projects, $accounts);
            $campaigns = $this->campaigns($workspaceId, $accounts, $projects);
            $conversions = $this->metrics($workspaceId, $campaigns);

            $this->budgets($workspaceId, $clients, $campaigns, $people['finance'], $conversions['spend']);
            $this->leads($workspaceId, $clients, $campaigns, $people, $conversions['leads']);
            $this->stores($workspaceId, $clients, $campaigns, $conversions['orders']);
            $this->work($workspaceId, $clients, $projects, $people);
            $this->tracking($workspaceId, $clients);

            foreach (['clients', 'projects', 'external_accounts', 'project_integration_bindings', 'campaigns', 'daily_metrics', 'budgets', 'leads', 'stores', 'store_orders', 'tasks', 'measurement_sources', 'observed_event_counts'] as $table) {
                DB::statement('analyze '.$table);
            }
        });

        $this->reportsAndPortals($workspaceId);

        $this->command?->info('Demo agency ready. Sign in with any demo address and the password "password".');
        $this->command?->warn('Every figure is a SANDBOX fixture, not real spend and not live provider data.');
    }

    /**
     * Saved reports with a frozen copy behind the client-safe ones, and a client portal for the first few clients —
     * made through the product's own use cases, as the owner, so the audit trail and the module grants are the ones a
     * real agency would have.
     */
    private function reportsAndPortals(string $workspaceId): void
    {
        $owner = DB::table('users')->where('email', 'owner@demo.digitalsmart.test')->value('id');

        if (! is_string($owner)) {
            return;
        }

        app(ActorContextBinder::class)->bind(new UserId($owner), new WorkspaceId($workspaceId));

        $clients = DB::table('clients')->where('workspace_id', $workspaceId)
            ->where('slug', 'like', self::TAG.'%')->where('status', 'active')
            ->orderBy('name')->limit(10)->get(['id', 'name']);

        $save = app(SaveReport::class);
        $snapshot = app(TakeSnapshot::class);

        foreach ($clients as $position => $client) {
            $clientId = (string) $client->id;
            $filters = new AnalyticsFilters(clientId: $clientId);

            $safe = $save(new ReportDefinition(
                name: 'Monthly performance',
                window: ReportWindow::LastMonth,
                comparison: ComparisonMode::PreviousPeriod,
                audience: ReportAudience::ClientSafe,
                filters: $filters,
                sections: [
                    new SectionDefinition(kind: SectionKind::Scorecard, metrics: [Metric::Spend, Metric::Clicks, Metric::Impressions]),
                    new SectionDefinition(kind: SectionKind::Trend, metrics: [Metric::Spend]),
                    new SectionDefinition(kind: SectionKind::Table, metrics: [Metric::Spend, Metric::Clicks], dimension: Dimension::Campaign),
                ],
                description: 'Written to be read by the client.',
                clientId: $clientId,
            ));

            if ($position % 2 === 0) {
                $snapshot($safe->id, 'Last month, as sent');
            }

            if ($position % 3 !== 2) {
                $save(new ReportDefinition(
                    name: 'Weekly review — internal',
                    window: ReportWindow::Last7Days,
                    comparison: ComparisonMode::PreviousPeriod,
                    audience: ReportAudience::Internal,
                    filters: $filters,
                    sections: [
                        new SectionDefinition(kind: SectionKind::Scorecard, metrics: [Metric::Spend, Metric::Clicks, Metric::Ctr, Metric::Cpc]),
                        new SectionDefinition(kind: SectionKind::Breakdown, metrics: [Metric::Spend], dimension: Dimension::Provider),
                    ],
                    description: 'What moved this week, with our own notes on it.',
                    clientId: $clientId,
                    internalNote: 'SANDBOX note — internal commentary never reaches a client link.',
                ));
            }

            if ($position < 5) {
                app(EnablePortal::class)($clientId);
                app(SetPortalModules::class)($clientId, [PortalModule::Performance, PortalModule::Reports, PortalModule::Tracking]);
            }
        }
    }

    /** Remove what an earlier run created, and only that. */
    private function clear(string $workspaceId): void
    {
        $accounts = DB::table('external_accounts')->where('workspace_id', $workspaceId)
            ->where('external_id', 'like', self::TAG.'%')->pluck('id')->all();

        if ($accounts !== []) {
            DB::table('daily_metrics')->whereIn('external_account_id', $accounts)->delete();
            DB::table('campaigns')->whereIn('external_account_id', $accounts)->delete();
            DB::table('project_integration_bindings')->whereIn('external_account_id', $accounts)->delete();
            DB::table('external_accounts')->whereIn('id', $accounts)->delete();
        }

        $clients = DB::table('clients')->where('workspace_id', $workspaceId)
            ->where('slug', 'like', self::TAG.'%')->pluck('id')->all();

        if ($clients !== []) {
            $stores = DB::table('stores')->whereIn('client_id', $clients)->pluck('id')->all();
            DB::table('store_orders')->whereIn('store_id', $stores)->delete();
            DB::table('stores')->whereIn('id', $stores)->delete();

            foreach (['leads', 'tasks', 'creative_requests', 'approval_requests', 'client_requests', 'budgets', 'reports'] as $table) {
                DB::table($table)->whereIn('client_id', $clients)->delete();
            }

            DB::table('projects')->whereIn('client_id', $clients)->delete();
            DB::table('clients')->whereIn('id', $clients)->delete();
        }

        DB::table('notifications')->where('workspace_id', $workspaceId)->where('title', 'like', '%·%')->delete();
    }

    /** @return array{owner: string, manager: string, buyer: string, creative: string, finance: string, buyers: list<string>, pms: list<string>, creatives: list<string>, all: list<string>} */
    private function people(string $workspaceId): array
    {
        $byEmail = fn (string $email): string => (string) DB::table('users')->where('email', $email)->value('id');

        $roles = DB::table('roles')->where('workspace_id', $workspaceId)->pluck('id', 'slug')->all();
        $financeRole = $this->financeRole($workspaceId);

        $added = [
            ['Sara Al-Dossari', 'sara@demo.digitalsmart.test', SystemRole::ProjectManager->value, 'project'],
            ['Omar Bin Saeed', 'omar@demo.digitalsmart.test', SystemRole::MediaBuyer->value, 'buyer'],
            ['Reem Al-Ghamdi', 'reem@demo.digitalsmart.test', SystemRole::MediaBuyer->value, 'buyer'],
            ['Lama Al-Shehri', 'lama@demo.digitalsmart.test', SystemRole::Creative->value, 'creative'],
            ['Khalid Al-Otaibi', 'admin@demo.digitalsmart.test', SystemRole::Admin->value, 'admin'],
            ['Hessa Al-Mutairi', 'finance@demo.digitalsmart.test', 'finance', 'finance'],
        ];

        $now = now();

        foreach ($added as [$name, $email, $role, $kind]) {
            $existing = DB::table('users')->where('email', $email)->value('id');
            $userId = is_string($existing) ? $existing : $this->ids->generate();

            if (! is_string($existing)) {
                DB::table('users')->insert([
                    'id' => $userId, 'name' => $name, 'email' => $email, 'password' => Hash::make('password'),
                    'locale' => 'en', 'timezone' => 'Asia/Riyadh', 'is_active' => true,
                    'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $roleId = $role === 'finance' ? $financeRole : ($roles[$role] ?? null);

            if (is_string($roleId) && ! DB::table('workspace_members')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])->exists()) {
                DB::table('workspace_members')->insert([
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $userId,
                    'role_id' => $roleId, 'status' => MembershipStatus::Active->value, 'joined_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            unset($kind);
        }

        return [
            'owner' => $byEmail('owner@demo.digitalsmart.test'),
            'manager' => $byEmail('manager@demo.digitalsmart.test'),
            'buyer' => $byEmail('buyer@demo.digitalsmart.test'),
            'creative' => $byEmail('creative@demo.digitalsmart.test'),
            'finance' => $byEmail('finance@demo.digitalsmart.test'),
            'buyers' => [$byEmail('buyer@demo.digitalsmart.test'), $byEmail('omar@demo.digitalsmart.test'), $byEmail('reem@demo.digitalsmart.test')],
            'pms' => [$byEmail('manager@demo.digitalsmart.test'), $byEmail('sara@demo.digitalsmart.test')],
            'creatives' => [$byEmail('creative@demo.digitalsmart.test'), $byEmail('lama@demo.digitalsmart.test')],
            'all' => [
                $byEmail('owner@demo.digitalsmart.test'), $byEmail('manager@demo.digitalsmart.test'), $byEmail('buyer@demo.digitalsmart.test'),
                $byEmail('creative@demo.digitalsmart.test'), $byEmail('sara@demo.digitalsmart.test'), $byEmail('omar@demo.digitalsmart.test'),
                $byEmail('reem@demo.digitalsmart.test'), $byEmail('lama@demo.digitalsmart.test'),
            ],
        ];
    }

    /**
     * A role for finance: sees every budget and every number, approves budgets, and cannot change a project,
     * decide an approval or administer the workspace — which is what makes the finance home appear.
     */
    private function financeRole(string $workspaceId): string
    {
        $existing = DB::table('roles')->where(['workspace_id' => $workspaceId, 'slug' => 'finance'])->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = $this->ids->generate();
        $now = now();

        DB::table('roles')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'name' => 'Finance',
            'slug' => 'finance', 'description' => 'Budgets, spend and forecasts across the agency.',
            'is_system' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $slugs = [
            'clients.view', 'projects.view_all', 'analytics.view', 'analytics.export', 'budgets.view', 'budgets.manage',
            'budgets.approve', 'reports.view', 'reports.manage', 'reports.share', 'leads.view', 'tasks.view', 'approvals.view',
        ];

        foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
            DB::table('permission_role')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
        }

        return $id;
    }

    /** @return list<array{id: string, name: string, slug: string, industry: string, currency: string, commerce: bool}> */
    private function clients(string $workspaceId, string $ownerId): array
    {
        $industries = array_keys(self::INDUSTRIES);
        $rows = [];
        $out = [];
        $now = now();
        $used = [];

        for ($i = 0; $i < 60; $i++) {
            $industry = $industries[$i % count($industries)];
            $spec = self::INDUSTRIES[$industry];
            $brand = self::BRANDS[($i * 7 + intdiv($i, 8)) % count(self::BRANDS)];
            $noun = $spec['nouns'][intdiv($i, count($industries)) % count($spec['nouns'])];
            $name = $brand.' '.$noun;

            while (in_array($name, $used, true)) {
                $brand = self::BRANDS[($i + count($used)) % count(self::BRANDS)];
                $name = $brand.' '.$noun.' '.['Group', 'Co.', 'KSA', 'Gulf'][count($used) % 4];
            }

            $used[] = $name;
            $id = $this->ids->generate();
            $currency = $i % 9 === 4 ? 'AED' : ($i % 17 === 8 ? 'USD' : 'SAR');
            $status = match (true) {
                $i % 23 === 22 => 'paused',
                $i % 19 === 18 => 'onboarding',
                default => 'active',
            };

            $out[] = ['id' => $id, 'name' => $name, 'slug' => self::TAG.($i + 1), 'industry' => $industry, 'currency' => $currency, 'commerce' => $spec['commerce'], 'status' => $status];
            $rows[] = [
                'id' => $id, 'workspace_id' => $workspaceId, 'name' => $name, 'slug' => self::TAG.($i + 1),
                'status' => $status, 'industry' => $industry, 'default_currency' => $currency,
                'is_demo' => true, 'created_by' => $ownerId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $this->insert('clients', $rows);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $clients
     * @return list<array{id: string, client_id: string, name: string, industry: string, currency: string, status: string, commerce: bool}>
     */
    private function projects(string $workspaceId, array $clients): array
    {
        $rows = [];
        $out = [];
        $now = now();
        $sequence = 0;

        foreach ($clients as $index => $client) {
            if ($client['status'] === 'onboarding') {
                $count = 1;
            } else {
                $count = 1 + ($index % 3);
            }

            $programmes = self::INDUSTRIES[$client['industry']]['programmes'];

            for ($p = 0; $p < $count; $p++) {
                $sequence++;
                $id = $this->ids->generate();
                $name = $programmes[($index + $p) % count($programmes)];
                $status = match (true) {
                    $client['status'] === 'onboarding' => 'onboarding',
                    $client['status'] === 'paused' => 'paused',
                    $sequence % 11 === 0 => 'closing',
                    $sequence % 7 === 0 => 'planning',
                    default => 'active',
                };

                $rows[] = [
                    'id' => $id, 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'name' => $name,
                    'slug' => self::TAG.'p-'.$sequence, 'status' => $status, 'currency' => $client['currency'],
                    'starts_on' => $now->copy()->subDays(20 + ($sequence * 7) % 70)->toDateString(),
                    'ends_on' => $now->copy()->addDays($status === 'closing' ? 9 : 25 + ($sequence * 11) % 110)->toDateString(),
                    'is_demo' => true, 'created_at' => $now, 'updated_at' => $now,
                ];
                $out[] = ['id' => $id, 'client_id' => $client['id'], 'name' => $name, 'industry' => $client['industry'], 'currency' => $client['currency'], 'status' => $status, 'commerce' => $client['commerce']];
            }
        }

        $this->insert('projects', $rows);

        return $out;
    }

    /**
     * Put people on projects, so "my projects" and "my campaigns" mean something for each role.
     *
     * @param  list<array<string, mixed>>  $projects
     * @param  array<string, mixed>  $people
     */
    private function members(array $projects, array $people): void
    {
        $roles = DB::table('roles')->pluck('id', 'slug')->all();
        $rows = [];
        $now = now();

        foreach ($projects as $index => $project) {
            $assign = [
                [$people['pms'][$index % 2], SystemRole::ProjectManager->value],
                [$people['buyers'][$index % 3], SystemRole::MediaBuyer->value],
            ];

            if ($index % 3 === 0) {
                $assign[] = [$people['creatives'][$index % 2], SystemRole::Creative->value];
            }

            foreach ($assign as [$user, $role]) {
                $rows[] = [
                    'id' => $this->ids->generate(), 'project_id' => $project['id'], 'user_id' => $user,
                    'role_id' => $roles[$role], 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('project_members')->upsert($chunk, ['project_id', 'user_id'], ['role_id']);
        }
    }

    /**
     * One agency connection per platform, holding **sandbox** credentials.
     *
     * Through the domain, the way the base demo does it, so each connection reads "Sandbox credentials" on the
     * integrations page rather than "Connected" with nothing behind it — a connection that claimed to be live with
     * no credential would be the one dishonest thing in a deliberately honest demo.
     *
     * @return array<string, string> provider => connection id
     */
    private function connections(string $workspaceId): array
    {
        $out = [];
        $now = now();
        $repository = app(ProviderConnectionRepository::class);

        foreach (array_keys(self::PLATFORMS) as $providerValue) {
            $provider = Provider::from($providerValue);
            $keys = $provider->requiredCredentialKeys();
            $existing = $repository->agencyConnection(new WorkspaceId($workspaceId), $provider);

            if ($keys === []) {
                /* A platform with no published API has nothing to authenticate with. */
                $id = $existing instanceof ProviderConnection ? $existing->id->value : $this->ids->generate();

                if (! $existing instanceof ProviderConnection) {
                    DB::table('provider_connections')->insert([
                        'id' => $id, 'workspace_id' => $workspaceId, 'provider' => $providerValue, 'owner_type' => 'agency',
                        'owner_client_id' => null, 'name' => 'SANDBOX — '.$provider->label(),
                        'status' => 'connected', 'credentials' => null, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                $out[$providerValue] = $id;

                continue;
            }

            $credentials = new Credentials(array_fill_keys($keys, Credentials::SANDBOX_TOKEN));

            if ($existing instanceof ProviderConnection) {
                $existing->replaceCredentials($credentials);
                $connection = $existing;
            } else {
                $connection = ProviderConnection::register(
                    id: new ProviderConnectionId($this->ids->generate()),
                    workspaceId: new WorkspaceId($workspaceId),
                    provider: $provider,
                    owner: ConnectionOwner::agency(),
                    name: 'SANDBOX — '.$provider->label(),
                    credentials: $credentials,
                );
            }

            $repository->save($connection);
            $out[$providerValue] = $connection->id->value;
        }

        return $out;
    }

    /** A sandbox-credentialled agency connection for one provider, reusing the workspace's own if it has one. */
    private function sandboxConnection(string $workspaceId, Provider $provider): string
    {
        $repository = app(ProviderConnectionRepository::class);
        $credentials = new Credentials(array_fill_keys($provider->requiredCredentialKeys(), Credentials::SANDBOX_TOKEN));
        $existing = $repository->agencyConnection(new WorkspaceId($workspaceId), $provider);

        if ($existing instanceof ProviderConnection) {
            $existing->replaceCredentials($credentials);
            $connection = $existing;
        } else {
            $connection = ProviderConnection::register(
                id: new ProviderConnectionId($this->ids->generate()),
                workspaceId: new WorkspaceId($workspaceId),
                provider: $provider,
                owner: ConnectionOwner::agency(),
                name: 'SANDBOX — '.$provider->label(),
                credentials: $credentials,
            );
        }

        $repository->save($connection);

        return $connection->id->value;
    }

    /**
     * What measures each client, and how well it is measuring.
     *
     * Most sources are healthy and reporting. A few have gone quiet for days, one in thirteen has synced but seen no
     * event for five, and onboarding clients have a property nobody has read yet — the shapes a tracking review
     * actually turns up. Event counts are aggregates per day, SANDBOX, and only exist for the days a source was
     * seeing events at all.
     *
     * @param  list<array<string, mixed>>  $clients
     */
    private function tracking(string $workspaceId, array $clients): void
    {
        $now = now();
        $connection = [];

        foreach ([Provider::GoogleAnalytics, Provider::GoogleTagManager, Provider::Salla, Provider::Zid, Provider::HubSpot] as $provider) {
            $connection[$provider->value] = $this->sandboxConnection($workspaceId, $provider);
        }

        $sources = [];
        $counts = [];
        $definitions = [];
        $mappings = [];

        foreach ($clients as $i => $client) {
            $commerce = (bool) $client['commerce'];
            $onboarding = $client['status'] === 'onboarding';
            $stale = ! $onboarding && $i % 9 === 4;
            $silent = ! $onboarding && ! $stale && $i % 13 === 6;

            $syncedAt = $onboarding ? null : ($stale ? $now->copy()->subDays(3) : $now->copy()->subHours(1 + $i % 6));
            $eventAt = $onboarding ? null : ($stale ? $now->copy()->subDays(3) : ($silent ? $now->copy()->subDays(5) : $now->copy()->subHour()));
            $lastEventDay = $eventAt?->copy()->startOfDay();
            $analyticsId = $this->ids->generate();

            $sources[] = [
                'id' => $analyticsId, 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'project_id' => null,
                'provider' => Provider::GoogleAnalytics->value, 'kind' => 'analytics',
                'external_id' => 'properties/'.(300_000_000 + $i * 7_919), 'name' => 'SANDBOX — '.$client['name'].' GA4',
                'provider_connection_id' => $connection[Provider::GoogleAnalytics->value],
                'last_synced_at' => $syncedAt, 'last_event_at' => $eventAt, 'is_active' => true, 'metadata' => '{}',
                'created_at' => $now, 'updated_at' => $now,
            ];

            if (! $onboarding && $i % 10 !== 9) {
                $sources[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'project_id' => null,
                    'provider' => Provider::GoogleTagManager->value, 'kind' => 'tag_manager',
                    'external_id' => 'accounts/'.(6_000_000 + $i).'/containers/'.(190_000_000 + $i * 31),
                    'name' => 'SANDBOX — '.$client['name'].' web container',
                    'provider_connection_id' => $connection[Provider::GoogleTagManager->value],
                    'last_synced_at' => $syncedAt, 'last_event_at' => null, 'is_active' => true, 'metadata' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            if (! $onboarding) {
                $platform = $commerce ? ($i % 2 === 0 ? Provider::Salla : Provider::Zid) : Provider::HubSpot;
                $sources[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'project_id' => null,
                    'provider' => $platform->value, 'kind' => $commerce ? 'store' : 'crm',
                    'external_id' => ($commerce ? 'store-' : 'portal-').(70_000 + $i),
                    'name' => 'SANDBOX — '.$client['name'].' '.$platform->label(),
                    'provider_connection_id' => $connection[$platform->value],
                    'last_synced_at' => $syncedAt, 'last_event_at' => $eventAt, 'is_active' => true, 'metadata' => '{}',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            if ($lastEventDay !== null) {
                $names = $commerce ? ['page_view', 'add_to_cart', 'begin_checkout', 'purchase'] : ['page_view', 'form_start', 'form_submit', 'generate_lead'];

                if ($i % 6 === 2) {
                    $names[] = 'cta_click_v2';
                }

                for ($day = 0; $day < 14; $day++) {
                    $date = $now->copy()->startOfDay()->subDays($day);

                    if ($date->greaterThan($lastEventDay)) {
                        continue;
                    }

                    foreach ($names as $step => $name) {
                        $base = 2_400 + (($i * 211) % 5_000);
                        $count = (int) max(3, $base / (1 + $step * 2.4) * (0.85 + $this->noise(30) / 100));
                        $value = $name === 'purchase' ? $count * (9_000 + ($i * 700) % 26_000) : null;
                        $counts[] = [
                            'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'measurement_source_id' => $analyticsId,
                            'raw_event_name' => $name, 'date' => $date->toDateString(), 'event_count' => $count,
                            'value_minor' => $value, 'currency' => $value === null ? null : $client['currency'],
                            'source' => 'sandbox', 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }

            if (! $onboarding) {
                $definitionId = $this->ids->generate();
                $definitions[] = [
                    'id' => $definitionId, 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'project_id' => null,
                    'name' => $commerce ? 'Purchase' : 'Lead', 'normalized_event' => $commerce ? 'purchase' : 'generate_lead',
                    'description' => $commerce ? 'A completed order.' : 'A submitted enquiry.',
                    'is_conversion' => true, 'is_primary' => true, 'expects_value' => $commerce,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                /* One client in seven has a primary conversion nobody has mapped an event to — a finding, on purpose. */
                if ($i % 7 !== 3) {
                    $mappings[] = [
                        'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'event_definition_id' => $definitionId,
                        'measurement_source_id' => null, 'raw_event_name' => $commerce ? 'purchase' : 'generate_lead',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        $this->insert('measurement_sources', $sources);
        $this->insert('observed_event_counts', $counts);
        $this->insert('event_definitions', $definitions);
        $this->insert('event_mappings', $mappings);
    }

    /**
     * One to three platform accounts per client, chosen by what the industry advertises on.
     *
     * @param  array<string, string>  $connections
     * @param  list<array<string, mixed>>  $clients
     * @return list<array{id: string, provider: string, currency: string, client_id: string, name: string, stale: bool}>
     */
    private function accounts(string $workspaceId, array $connections, array $clients): array
    {
        $rows = [];
        $out = [];
        $now = now();
        $mixes = [['meta', 'google_ads'], ['meta', 'tiktok', 'snapchat'], ['google_ads', 'meta'], ['meta', 'snapchat'], ['google_ads', 'linkedin'], ['meta', 'tiktok', 'google_ads'], ['meta', 'x'], ['meta', 'google_ads', 'openai']];
        $counter = 0;

        foreach ($clients as $index => $client) {
            if ($client['status'] === 'onboarding') {
                continue;
            }

            foreach ($mixes[$index % count($mixes)] as $slot => $provider) {
                $counter++;
                $id = $this->ids->generate();
                $label = match ($provider) {
                    'meta' => 'Meta Ads', 'google_ads' => 'Google Ads', 'tiktok' => 'TikTok Ads', 'snapchat' => 'Snapchat Ads',
                    'x' => 'X Ads', 'linkedin' => 'LinkedIn Ads', default => 'ChatGPT Ads',
                };
                $stale = $counter % 29 === 0;
                $disabled = $counter % 41 === 0;

                $out[] = ['id' => $id, 'provider' => $provider, 'currency' => $client['currency'], 'client_id' => $client['id'], 'name' => $client['name'].' — '.$label, 'stale' => $stale];
                $rows[] = [
                    'id' => $id, 'workspace_id' => $workspaceId, 'provider_connection_id' => $connections[$provider],
                    'provider' => $provider, 'external_id' => self::TAG.$provider.'-'.str_pad((string) $counter, 5, '0', STR_PAD_LEFT),
                    'name' => $client['name'].' — '.$label, 'currency' => $client['currency'], 'timezone' => 'Asia/Riyadh',
                    'status' => $disabled ? 'disabled' : 'active', 'metadata' => '{}',
                    'discovered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
                unset($slot);
            }
        }

        $this->insert('external_accounts', $rows);

        return $out;
    }

    /**
     * Bind each account to its client's projects — one, or two where a client runs two programmes on one
     * account (so a few campaigns are honestly ambiguous) — and leave a couple of accounts unbound.
     *
     * @param  list<array<string, mixed>>  $projects
     * @param  list<array<string, mixed>>  $accounts
     */
    private function bindings(string $workspaceId, array $projects, array $accounts): void
    {
        $byClient = [];

        foreach ($projects as $project) {
            $byClient[$project['client_id']][] = $project;
        }

        $rows = [];
        $now = now();

        foreach ($accounts as $index => $account) {
            if ($index % 37 === 36) {
                continue;
            }

            $own = $byClient[$account['client_id']] ?? [];
            $targets = $index % 9 === 0 ? array_slice($own, 0, 2) : array_slice($own, $index % max(1, count($own)), 1);

            foreach ($targets as $project) {
                $rows[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'project_id' => $project['id'],
                    'external_account_id' => $account['id'], 'sync_enabled' => true, 'bound_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('project_integration_bindings')->upsert($chunk, ['project_id', 'external_account_id'], ['sync_enabled']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $projects
     * @return list<array<string, mixed>>
     */
    private function campaigns(string $workspaceId, array $accounts, array $projects): array
    {
        $industryOf = [];

        foreach ($projects as $project) {
            $industryOf[$project['client_id']] = $project['industry'];
        }

        $rows = [];
        $out = [];
        $now = now();
        $sequence = 0;

        foreach ($accounts as $account) {
            $industry = $industryOf[$account['client_id']] ?? 'Fashion & Retail';
            $spec = self::INDUSTRIES[$industry];
            $count = 2 + ($sequence % 3);

            for ($c = 0; $c < $count; $c++) {
                $sequence++;
                $id = $this->ids->generate();
                $objective = $spec['objectives'][($sequence + $c) % count($spec['objectives'])];
                $programme = $spec['programmes'][($sequence + $c) % count($spec['programmes'])];
                $pattern = self::PATTERNS[$sequence % count(self::PATTERNS)];
                $platformName = Provider::from($account['provider'])->label();
                $audience = ['Riyadh', 'Jeddah', 'Eastern Province', 'GCC', 'Lookalike 1%', 'Retargeting'][($sequence + $c) % 6];
                $status = match (true) {
                    $sequence % 11 === 0 => 'paused',
                    $sequence % 17 === 0 => 'completed',
                    default => 'active',
                };
                $dailyBudget = (int) (40_000 + (($sequence * 7_919) % 360_000));
                $endsSoon = $status === 'active' && $sequence % 13 === 0;

                $out[] = [
                    'id' => $id, 'account_id' => $account['id'], 'provider' => $account['provider'], 'currency' => $account['currency'],
                    'client_id' => $account['client_id'], 'objective' => $objective, 'pattern' => $pattern, 'status' => $status,
                    'budget' => $dailyBudget, 'stale' => $account['stale'], 'name' => $programme.' — '.$audience, 'programme' => $programme,
                ];
                $rows[] = [
                    'id' => $id, 'workspace_id' => $workspaceId, 'external_account_id' => $account['id'], 'project_id' => null,
                    'provider' => $account['provider'], 'external_id' => self::TAG.'c-'.$sequence,
                    'name' => $programme.' — '.$audience.' ('.$platformName.')', 'objective' => $objective,
                    'status' => $status, 'budget_mode' => 'daily', 'budget_minor' => $dailyBudget,
                    'currency' => $account['currency'],
                    'starts_at' => $now->copy()->subDays(20 + $sequence % 40),
                    'ends_at' => $endsSoon ? $now->copy()->addDays(2 + $sequence % 5) : null,
                    'metadata' => '{}', 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->insert('campaigns', $rows);

        return $out;
    }

    /**
     * Sixty days of daily rows per campaign, shaped by the campaign's pattern and the calendar.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{spend: array<string, int>, leads: array<string, array<string, int>>, orders: array<string, array<string, int>>}
     */
    private function metrics(string $workspaceId, array $campaigns): array
    {
        $today = new DateTimeImmutable('today');
        $rows = [];
        $spendByClient = [];
        $leads = [];
        $orders = [];
        $month = $today->format('Y-m');

        foreach ($campaigns as $campaign) {
            $profile = self::PLATFORMS[$campaign['provider']];
            $isRevenue = in_array($campaign['objective'], ['sales', 'app_promotion'], true);
            $aov = 11_000 + (crc32($campaign['id']) % 28_000);

            for ($offset = self::DAYS - 1; $offset >= 0; $offset--) {
                $date = $today->modify('-'.$offset.' days');
                $i = self::DAYS - 1 - $offset;

                if ($campaign['stale'] && $offset < 3) {
                    continue;
                }

                if ($campaign['status'] === 'completed' && $offset < 12) {
                    continue;
                }

                if ($campaign['status'] === 'paused' && $offset < 6) {
                    continue;
                }

                [$spendFactor, $resultFactor] = $this->shape($campaign['pattern'], $i, self::DAYS);
                $dow = (int) $date->format('w');
                $weekend = in_array($dow, [5, 6], true) ? 0.82 : 1.0;
                $dom = (int) $date->format('j');
                $payday = $dom >= 25 && $dom <= 27 ? 1.25 : 1.0;
                $jitter = 0.92 + $this->noise(16) / 100;

                $spendFloat = $campaign['budget'] * 0.72 * $spendFactor * $weekend * $payday * $jitter;
                $spend = (int) max(0, round($spendFloat));
                $impressions = (int) round($spend / 100 / $profile['cpm'] * 1000);
                $clicks = (int) round($impressions * $profile['ctr'] * (0.9 + $this->noise(25) / 100) * $resultFactor);
                $conversions = 0.0;
                $revenue = null;
                $videoViews = null;
                $engagements = null;
                $linkClicks = null;

                switch ($campaign['objective']) {
                    case 'leads':
                    case 'messages':
                        $conversions = round($clicks * (0.035 + $this->noise(30) / 1000) * $resultFactor, 4);
                        break;
                    case 'sales':
                    case 'app_promotion':
                        $conversions = round($clicks * (0.009 + $this->noise(8) / 1000) * $resultFactor, 4);
                        $revenue = (int) round($conversions * $aov);
                        break;
                    case 'video_views':
                        $videoViews = (int) round($impressions * 0.27);
                        break;
                    case 'traffic':
                        $linkClicks = (int) round($clicks * 0.88);
                        break;
                    default:
                        $engagements = (int) round($impressions * 0.028);
                }

                if ($campaign['pattern'] === 'tracking' && $offset < 5 && $isRevenue) {
                    $conversions = 0.0;
                    $revenue = 0;
                }

                if ($campaign['pattern'] === 'tracking' && $offset < 5 && ! $isRevenue && $campaign['objective'] === 'leads') {
                    $conversions = 0.0;
                }

                $rows[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'external_account_id' => $campaign['account_id'],
                    'provider' => $campaign['provider'], 'entity_type' => 'campaign', 'entity_id' => $campaign['id'],
                    'external_entity_id' => $campaign['id'], 'campaign_id' => $campaign['id'], 'date' => $date->format('Y-m-d'),
                    'account_timezone' => 'Asia/Riyadh', 'currency' => $campaign['currency'], 'spend_minor' => $spend,
                    'revenue_minor' => $revenue, 'impressions' => $impressions, 'reach' => (int) round($impressions * 0.68),
                    'clicks' => $clicks, 'link_clicks' => $linkClicks, 'video_views' => $videoViews,
                    'engagements' => $engagements, 'conversions' => $conversions, 'source' => 'sandbox', 'metadata' => '{}',
                    'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ];

                if ($date->format('Y-m') === $month) {
                    $spendByClient[$campaign['client_id']] = ($spendByClient[$campaign['client_id']] ?? 0) + $spend;
                }

                if (in_array($campaign['objective'], ['leads', 'messages'], true)) {
                    $leads[$campaign['client_id']][$campaign['id'].'|'.$date->format('Y-m-d')] = (int) round($conversions);
                }

                if ($campaign['objective'] === 'sales') {
                    $orders[$campaign['client_id']][$campaign['id'].'|'.$date->format('Y-m-d')] = (int) round($conversions);
                }

                if (count($rows) >= self::CHUNK) {
                    DB::table('daily_metrics')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('daily_metrics')->insert($rows);
        }

        return ['spend' => $spendByClient, 'leads' => $leads, 'orders' => $orders];
    }

    /**
     * The shape of a campaign's life: how its spend and its results move across the window.
     *
     * Spend and results move separately on purpose — fatigue is results falling while spend holds, a collapse is
     * both, and a recovery is the same line coming back. Flat random noise would make every chart read as
     * nothing.
     *
     * @return array{0: float, 1: float}
     */
    private function shape(string $pattern, int $i, int $days): array
    {
        $t = $i / max(1, $days - 1);

        return match ($pattern) {
            'growth' => [0.65 + 0.7 * $t, 0.8 + 0.5 * $t],
            'decline' => [1.0, $t < 0.5 ? 1.0 : 1.0 - ($t - 0.5) * 1.1],
            'launch' => [min(1.0, 0.25 + $i / 10), min(1.0, 0.3 + $i / 14)],
            'fatigue' => [1.0, 1.15 - 0.55 * $t],
            'recovery' => [1.0, $t < 0.35 ? 1.0 : ($t < 0.6 ? 0.55 : 0.55 + ($t - 0.6) * 1.4)],
            'overspend' => [$t < 0.6 ? 1.0 : 1.0 + ($t - 0.6) * 2.2, 0.95],
            'tracking' => [1.0, 1.0],
            default => [1.0, 1.0 + 0.04 * sin($i / 4)],
        };
    }

    /**
     * Monthly budgets sized against what each client actually spends, so some are comfortable, some are
     * running hot and some are quietly under-delivering — and a few are still drafts waiting for approval.
     *
     * @param  list<array<string, mixed>>  $clients
     * @param  list<array<string, mixed>>  $campaigns
     * @param  array<string, int>  $spendThisMonth
     */
    private function budgets(string $workspaceId, array $clients, array $campaigns, string $financeId, array $spendThisMonth): void
    {
        $rows = [];
        $now = now();
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $dayOfMonth = max(1, (int) $now->format('j'));
        $daysInMonth = (int) $now->format('t');

        foreach ($clients as $index => $client) {
            $spent = $spendThisMonth[$client['id']] ?? 0;

            if ($spent === 0) {
                continue;
            }

            $projected = $spent / $dayOfMonth * $daysInMonth;
            $ratio = [1.35, 1.1, 0.98, 0.85, 1.2, 0.72][$index % 6];
            $amount = max(100_000, (int) round($projected * $ratio / 100_000) * 100_000);
            $draft = $index % 12 === 7;

            $rows[] = [
                'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'project_id' => null,
                'name' => $now->format('F Y').' media budget', 'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(), 'amount_minor' => $amount, 'currency' => $client['currency'],
                'status' => $draft ? 'draft' : 'approved', 'created_by' => $financeId,
                'approved_by' => $draft ? null : $financeId, 'approved_at' => $draft ? null : $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        $this->insert('budgets', $rows);

        /*
         * The plan under each approved budget: the money split by platform in proportion to how many of the
         * client's campaigns run there, with a tenth left unallocated on every third one — so the planned,
         * allocated and unallocated figures all have something to say.
         */
        $providersOf = [];

        foreach ($campaigns as $campaign) {
            $providersOf[$campaign['client_id']][$campaign['provider']] = ($providersOf[$campaign['client_id']][$campaign['provider']] ?? 0) + 1;
        }

        $allocations = [];

        foreach ($rows as $position => $budget) {
            $mix = $providersOf[$budget['client_id']] ?? [];

            if ($budget['status'] !== 'approved' || $mix === []) {
                continue;
            }

            $pool = $position % 3 === 0 ? (int) floor($budget['amount_minor'] * 0.9) : $budget['amount_minor'];
            $total = array_sum($mix);
            $assigned = 0;
            $last = array_key_last($mix);

            foreach ($mix as $provider => $count) {
                $amount = $provider === $last ? $pool - $assigned : (int) floor($pool * $count / $total);
                $assigned += $amount;

                if ($amount <= 0) {
                    continue;
                }

                $allocations[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'budget_id' => $budget['id'],
                    'parent_id' => null, 'level' => 'platform', 'provider' => $provider,
                    'label' => ucfirst(str_replace('_', ' ', $provider)), 'amount_minor' => $amount,
                    'position' => count($allocations), 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->insert('budget_allocations', $allocations);
    }

    /**
     * Enquiries that follow the campaigns that produced them, with owners, a believable stage by age, and
     * values only on the ones that were won.
     *
     * @param  list<array<string, mixed>>  $clients
     * @param  list<array<string, mixed>>  $campaigns
     * @param  array<string, mixed>  $people
     * @param  array<string, array<string, int>>  $generated
     */
    private function leads(string $workspaceId, array $clients, array $campaigns, array $people, array $generated): void
    {
        $nameOf = [];

        foreach ($campaigns as $campaign) {
            $nameOf[$campaign['id']] = $campaign['name'];
        }

        $currencyOf = [];

        foreach ($clients as $client) {
            $currencyOf[$client['id']] = $client['currency'];
        }

        $rows = [];
        $now = now();
        $today = new DateTimeImmutable('today');
        $sources = ['form_submission', 'form_submission', 'whatsapp_message', 'whatsapp_message', 'phone_call'];

        foreach ($generated as $clientId => $byKey) {
            $made = 0;

            foreach ($byKey as $key => $count) {
                if ($count <= 0 || $made >= 220) {
                    continue;
                }

                [$campaignId, $day] = explode('|', $key);
                $date = new DateTimeImmutable($day);
                $age = (int) $today->diff($date)->days;

                for ($n = 0; $n < min($count, 3); $n++) {
                    $made++;
                    $roll = $this->noise(100);
                    $stage = match (true) {
                        $age <= 2 => $roll < 70 ? 'new' : 'contacted',
                        $age <= 10 => $roll < 25 ? 'new' : ($roll < 55 ? 'contacted' : ($roll < 80 ? 'qualified' : 'lost')),
                        default => $roll < 10 ? 'contacted' : ($roll < 30 ? 'qualified' : ($roll < 55 ? 'won' : 'lost')),
                    };
                    $closed = in_array($stage, ['won', 'lost'], true);
                    $received = $date->setTime(8 + $this->noise(12), $this->noise(60));
                    $first = self::FIRST[$this->noise(count(self::FIRST))];
                    $last = self::LAST[$this->noise(count(self::LAST))];
                    $won = $stage === 'won';

                    $rows[] = [
                        'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'client_id' => $clientId, 'project_id' => null,
                        'source_kind' => $sources[$this->noise(count($sources))], 'name' => $first.' '.$last,
                        'stage' => $stage, 'owner_id' => $stage === 'new' ? null : $people['all'][$this->noise(count($people['all']))],
                        'value_minor' => $won ? 150_000 + $this->noise(900_000) : null,
                        'currency' => $won ? ($currencyOf[$clientId] ?? 'SAR') : null,
                        'attribution_evidence' => 'utm_parameters', 'utm_source' => 'paid', 'utm_medium' => 'cpc',
                        'utm_campaign' => $nameOf[$campaignId] ?? 'always-on',
                        'received_at' => $received, 'first_contacted_at' => $stage === 'new' ? null : $received->modify('+'.(1 + $this->noise(20)).' hours'),
                        'qualified_at' => in_array($stage, ['qualified', 'won'], true) ? $received->modify('+2 days') : null,
                        'closed_at' => $closed ? $received->modify('+'.(2 + $this->noise(9)).' days') : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        $this->insert('leads', $rows);
    }

    /**
     * A Salla or Zid store for each commerce client, with paid orders that follow the sales campaigns — some
     * traced to marketing by their campaign, most not, a few refunded.
     *
     * @param  list<array<string, mixed>>  $clients
     * @param  list<array<string, mixed>>  $campaigns
     * @param  array<string, array<string, int>>  $generated
     */
    private function stores(string $workspaceId, array $clients, array $campaigns, array $generated): void
    {
        $nameOf = [];

        foreach ($campaigns as $campaign) {
            $nameOf[$campaign['id']] = $campaign['name'];
        }

        $stores = [];
        $orders = [];
        $now = now();
        $today = new DateTimeImmutable('today');

        foreach ($clients as $index => $client) {
            if (! $client['commerce'] || $client['status'] !== 'active') {
                continue;
            }

            $storeId = $this->ids->generate();
            $provider = $index % 2 === 0 ? 'salla' : 'zid';
            $stores[] = [
                'id' => $storeId, 'workspace_id' => $workspaceId, 'client_id' => $client['id'], 'provider' => $provider,
                'external_id' => self::TAG.'store-'.($index + 1), 'name' => $client['name'].' Store',
                'currency' => $client['currency'], 'timezone' => 'Asia/Riyadh', 'is_active' => true,
                /* Most stores were read this morning; one in eleven has not been for four days. */
                'last_synced_at' => $index % 11 === 5 ? $now->copy()->subDays(4) : $now->copy()->subHours(1 + $index % 5),
                'orders_synced_through' => $index % 11 === 5 ? $now->copy()->subDays(4) : $now->copy()->subHours(1 + $index % 5),
                'created_at' => $now, 'updated_at' => $now,
            ];

            $n = 0;

            foreach ($generated[$client['id']] ?? [] as $key => $count) {
                [$campaignId, $day] = explode('|', $key);
                $date = new DateTimeImmutable($day);

                for ($k = 0; $k < min($count + 1, 6); $k++) {
                    $n++;
                    $total = 12_000 + $this->noise(90_000);
                    $traced = $this->noise(100) < 32;
                    $orders[] = [
                        'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'store_id' => $storeId,
                        'external_id' => 'ord-'.($index + 1).'-'.$n, 'payment_state' => 'paid',
                        'fulfilment_state' => $date < $today->modify('-3 days') ? 'delivered' : 'processing',
                        'currency' => $client['currency'], 'subtotal_minor' => $total, 'total_minor' => $total,
                        'refunded_minor' => $this->noise(100) < 4 ? intdiv($total, 3) : 0,
                        'attribution_evidence' => $traced ? 'utm_parameters' : 'none',
                        'utm_source' => $traced ? 'paid' : null, 'utm_medium' => $traced ? 'cpc' : null,
                        'utm_campaign' => $traced ? ($nameOf[$campaignId] ?? null) : null,
                        'revenue_source' => $provider, 'source' => 'sandbox',
                        'placed_at' => $date->setTime(9 + $this->noise(13), $this->noise(60)),
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        $this->insert('stores', $stores);
        $this->insert('store_orders', $orders);
    }

    /**
     * The working day around the media: tasks (some overdue), creative in every stage, approvals waiting,
     * clients asking for things, and a notification or two for everyone.
     *
     * @param  list<array<string, mixed>>  $clients
     * @param  list<array<string, mixed>>  $projects
     * @param  array<string, mixed>  $people
     */
    private function work(string $workspaceId, array $clients, array $projects, array $people): void
    {
        $now = now();
        $tasks = [];
        $creative = [];
        $approvals = [];
        $requests = [];
        $notify = [];

        $taskTitles = ['Refresh creative for weekend push', 'Rebuild audience exclusions', 'Prepare mid-month performance review', 'Fix purchase event on checkout', 'Reconcile spend with client invoice', 'Draft proposal for next quarter', 'Update landing page copy', 'Audit conversion tracking'];
        $creativeTitles = [
            ['Ramadan hero video — 15s', 'video'], ['Carousel: bestsellers', 'carousel'], ['Static set — national day', 'image'],
            ['Story ads — product launch', 'video'], ['Offer banner — white friday', 'image'], ['Testimonial reel', 'video'],
            ['Lead form copy — services', 'copy'], ['Search ads copy — brand', 'copy'], ['Carousel: new arrivals', 'carousel'],
        ];
        $states = ['draft', 'briefed', 'in_production', 'in_production', 'in_review', 'changes_requested', 'approved'];

        $creativeIndex = 0;

        foreach ($projects as $index => $project) {
            $assignee = $people['all'][$index % count($people['all'])];
            $assigner = $assignee === $people['owner'] ? $people['manager'] : $people['owner'];

            for ($t = 0; $t < 1 + $index % 3; $t++) {
                $sequence = $index * 3 + $t;
                $overdue = $sequence % 4 === 0;
                $state = ['todo', 'in_progress', 'in_review', 'done'][$sequence % 4];
                $taskId = $this->ids->generate();
                $tasks[] = [
                    'id' => $taskId, 'workspace_id' => $workspaceId, 'client_id' => $project['client_id'], 'project_id' => $project['id'],
                    'title' => $taskTitles[$sequence % count($taskTitles)], 'state' => $state,
                    'priority' => ['normal', 'high', 'normal', 'urgent', 'low'][$sequence % 5], 'assignee_id' => $assignee,
                    'created_by' => $people['owner'],
                    'due_on' => $now->copy()->addDays($overdue ? -(1 + $sequence % 9) : 1 + $sequence % 12)->toDateString(),
                    'completed_at' => $state === 'done' ? $now : null, 'state_changed_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                if ($state !== 'done' && $sequence % 6 === 0) {
                    $notify[] = [
                        'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $assignee, 'actor_id' => $assigner,
                        'kind' => 'task_assigned', 'title' => $taskTitles[$sequence % count($taskTitles)].' · '.$project['name'], 'body' => null,
                        'subject_type' => 'task', 'subject_id' => $taskId, 'read_at' => null,
                        'created_at' => $now->copy()->subHours($sequence % 30), 'updated_at' => $now,
                    ];
                }
            }

            if ($index % 2 === 0) {
                /* Its own counter: every request here has an even project index, so indexing by it would use half of everything. */
                $n = $creativeIndex++;
                $creative[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'client_id' => $project['client_id'], 'project_id' => $project['id'],
                    'title' => $creativeTitles[($n * 4 + 1) % count($creativeTitles)][0], 'state' => $states[($n * 3 + intdiv($n, 7)) % count($states)],
                    'kind' => $creativeTitles[($n * 4 + 1) % count($creativeTitles)][1], 'provider' => ['meta', 'tiktok', 'snapchat', 'google_ads'][$n % 4],
                    'due_at' => $now->copy()->addDays(($n % 10) - 3), 'owner_id' => $n % 9 === 8 ? null : $people['creatives'][$n % 2],
                    'created_by' => $people['owner'], 'state_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            if ($index % 5 === 0) {
                $proposal = ['campaign' => $project['name'], 'change' => 'Increase daily budget by 15%'];
                $approvalId = $this->ids->generate();
                $approvalTitle = ['Raise daily budget — '.$project['name'], 'Publish new creative set', 'Launch '.$project['name']][$index % 3];

                /* The people who could decide it are told once each — and the person who asked is not one of them. */
                foreach ([$people['owner'], $people['manager']] as $who) {
                    $notify[] = [
                        'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $who, 'actor_id' => $people['buyers'][$index % 3],
                        'kind' => 'approval_requested', 'title' => $approvalTitle, 'body' => 'Waiting for a decision.',
                        'subject_type' => 'approval', 'subject_id' => $approvalId, 'read_at' => $index % 4 === 0 ? $now : null,
                        'created_at' => $now->copy()->subHours(2 + $index % 40), 'updated_at' => $now,
                    ];
                }

                $approvals[] = [
                    'id' => $approvalId, 'workspace_id' => $workspaceId, 'client_id' => $project['client_id'], 'project_id' => $project['id'],
                    'kind' => ['spend_change', 'creative_publish', 'campaign_launch'][$index % 3],
                    'title' => ['Raise daily budget — '.$project['name'], 'Publish new creative set', 'Launch '.$project['name']][$index % 3],
                    'rationale' => 'Performance is ahead of plan and the budget is the constraint.',
                    'proposal' => json_encode($proposal, JSON_THROW_ON_ERROR),
                    'proposal_fingerprint' => hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)),
                    'state' => 'pending', 'enactment' => 'not_required', 'requested_by' => $people['buyers'][$index % 3],
                    'created_at' => $now->copy()->subDays($index % 9), 'updated_at' => $now,
                ];
            }

            if ($index % 6 === 1) {
                $requestId = $this->ids->generate();
                $notify[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $people['pms'][$index % 2], 'actor_id' => null,
                    'kind' => 'client_request_submitted', 'title' => ['Can we pause the weekend campaigns?', 'Need the monthly report before Thursday', 'New offer creatives for next week', 'Why did cost per lead go up?', 'Launch a campaign for the new branch'][$index % 5],
                    'body' => 'A client asked for something.', 'subject_type' => 'client_request', 'subject_id' => $requestId,
                    'read_at' => null, 'created_at' => $now->copy()->subHours(1 + $index % 30), 'updated_at' => $now,
                ];
                $requests[] = [
                    'id' => $requestId, 'workspace_id' => $workspaceId, 'client_id' => $project['client_id'], 'project_id' => $project['id'],
                    'kind' => ['change', 'report', 'creative', 'question', 'new_campaign'][$index % 5],
                    'title' => ['Can we pause the weekend campaigns?', 'Need the monthly report before Thursday', 'New offer creatives for next week', 'Why did cost per lead go up?', 'Launch a campaign for the new branch'][$index % 5],
                    'state' => ['submitted', 'in_progress', 'accepted', 'submitted'][$index % 4], 'stated_urgency' => ['this_week', 'whenever', 'urgent', 'this_month'][$index % 4],
                    'state_changed_at' => $now, 'created_at' => $now->copy()->subDays($index % 6), 'updated_at' => $now,
                ];
            }
        }

        $this->insert('tasks', $tasks);
        $this->insert('creative_requests', $creative);

        /*
         * A file behind every request that has got as far as production: measured the way an upload is, and decided
         * the way a review is — approved where the request says so, sent back with a reason where it says that.
         */
        $shapes = ['image' => [1080, 1080, 'jpg'], 'video' => [1080, 1920, 'mp4'], 'carousel' => [1080, 1350, 'jpg'], 'copy' => [null, null, null]];
        $versions = [];
        $decisions = [];

        foreach ($creative as $request) {
            if (in_array($request['state'], ['draft', 'briefed'], true)) {
                continue;
            }

            [$width, $height, $format] = $shapes[$request['kind']];
            $versionId = $this->ids->generate();
            $versions[] = [
                'id' => $versionId, 'workspace_id' => $workspaceId, 'creative_request_id' => $request['id'], 'number' => 1,
                'kind' => $request['kind'], 'label' => 'First cut', 'storage_path' => $request['kind'] === 'copy' ? null : 'demo/'.$request['id'].'.'.$format,
                'body' => $request['kind'] === 'copy' ? 'Demo copy — sandbox text.' : null,
                'width_px' => $width, 'height_px' => $height, 'format' => $format,
                'file_size_bytes' => $request['kind'] === 'video' ? 8_400_000 : ($request['kind'] === 'copy' ? null : 640_000),
                'duration_seconds' => $request['kind'] === 'video' ? 15.0 : null,
                'text_length' => $request['kind'] === 'copy' ? 25 : null,
                'uploaded_by' => $request['owner_id'], 'created_at' => $now, 'updated_at' => $now,
            ];

            if ($request['state'] === 'approved' || $request['state'] === 'changes_requested') {
                $approved = $request['state'] === 'approved';
                $decisions[] = [
                    'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'creative_version_id' => $versionId,
                    'outcome' => $approved ? 'approved' : 'changes_requested',
                    'reason' => $approved ? null : 'The offer line is too small on mobile.',
                    'decided_by' => $people['manager'], 'decided_at' => $now->copy()->subHours(2 + count($decisions)),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->insert('creative_versions', $versions);
        $this->insert('creative_approvals', $decisions);
        $this->insert('approval_requests', $approvals);
        $this->insert('client_requests', $requests);
        $this->insert('notifications', $notify);

        unset($clients);
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function noise(int $bound): int
    {
        $this->seed = ($this->seed * 1_103_515_245 + 12_345) & 0x7FFFFFFF;

        return $this->seed % $bound;
    }
}
