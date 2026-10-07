<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Commerce\Command\SyncStore;
use App\Application\Creative\Command\AddCreativeVersion;
use App\Application\Creative\Command\CommentOnRequest;
use App\Application\Creative\Command\DecideOnVersion;
use App\Application\Creative\Command\MoveCreativeRequest;
use App\Application\Creative\Command\OpenCreativeRequest;
use App\Application\Creative\DTO\CommentInput;
use App\Application\Creative\DTO\CreativeRequestInput;
use App\Application\Creative\DTO\VersionInput;
use App\Application\Leads\Command\ChangeLeadStage;
use App\Application\Leads\Command\RecordLead;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Measurement\Command\SaveMeasurementSource;
use App\Application\Measurement\Command\SyncMeasurementSource;
use App\Application\Measurement\DTO\MeasurementSourceInput;
use App\Application\Operations\Command\MoveTask;
use App\Application\Operations\Command\OpenTask;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Client\ClientStatus;
use App\Domain\Commerce\OrderAttribution;
use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeState;
use App\Domain\Identity\UserId;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use App\Domain\Measurement\MeasurementSourceKind;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Project\ProjectStatus;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use App\Infrastructure\Persistence\Eloquent\Model\Store;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A workspace for local development and demos.
 *
 * Everything it creates is labelled DEMO and flagged `is_demo`, and it refuses to
 * run in production.
 *
 * It now also connects two providers in **sandbox** mode, binds an account to a
 * project and runs the real sync, which earlier versions of this file said it
 * would never do. The reasoning behind that refusal still stands — a fabricated
 * spend figure in front of a client is unrecoverable — but a demo workspace with
 * no performance data cannot demonstrate, or test, the analytics layer at all.
 *
 * What makes it safe is not restraint here but the labelling downstream: every row
 * the sync writes carries `source = sandbox`, aggregates inherit their weakest
 * input, and the interface marks the whole surface. The figures cannot be mistaken
 * for a client's because the product says what they are on every screen that shows
 * them. See docs/INTEGRATIONS.md.
 */
final class DemoWorkspaceSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** @var list<array{name: string, email: string, role: SystemRole}> */
    private const PEOPLE = [
        ['name' => 'Demo Owner', 'email' => 'owner@demo.digitalsmart.test', 'role' => SystemRole::Owner],
        ['name' => 'Demo Manager', 'email' => 'manager@demo.digitalsmart.test', 'role' => SystemRole::ProjectManager],
        ['name' => 'Demo Buyer', 'email' => 'buyer@demo.digitalsmart.test', 'role' => SystemRole::MediaBuyer],
        ['name' => 'Demo Creative', 'email' => 'creative@demo.digitalsmart.test', 'role' => SystemRole::Creative],
        ['name' => 'Demo Client', 'email' => 'client@demo.digitalsmart.test', 'role' => SystemRole::ClientViewer],
    ];

    public function __construct(private readonly WorkspaceProvisioner $provisioner) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Refusing to seed demo data in production.');

            return;
        }

        $this->provisioner->syncPermissionCatalogue();

        $owner = $this->user(self::PEOPLE[0]);

        $workspace = $this->provisioner->provision(
            name: 'DEMO — Smart Code Agency',
            slug: 'demo-smart-code',
            owner: $owner,
            defaultCurrency: 'SAR',
            timezone: 'Asia/Riyadh',
        );

        $roles = $this->provisioner->createSystemRoles($workspace);

        foreach (array_slice(self::PEOPLE, 1) as $person) {
            WorkspaceMember::query()->updateOrCreate(
                ['workspace_id' => $workspace->getKey(), 'user_id' => $this->user($person)->getKey()],
                [
                    'role_id' => $roles[$person['role']->value]->getKey(),
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                ],
            );
        }

        $this->clients($workspace->getKey(), $owner->getKey());
        $this->sandboxPerformance($workspace->getKey());

        /*
         * Leads and the counted taps beside them, so the demo can show the one thing the Lead
         * Center exists to refuse: a tap-to-lead rate. With an empty pipeline the page is all
         * empty states, and the honesty rules it enforces are invisible.
         *
         * Both go through the real use cases, which means binding an actor — the commands check a
         * permission, as they should, and a seeder that bypassed the guard would be exercising a
         * path no live account can take. The owner is bound the same way the request middleware
         * binds one, rather than by logging in: there is no request here to carry a session.
         */
        $actor = app(ActorContextBinder::class);
        $actor->bind(new UserId((string) $owner->getKey()), new WorkspaceId((string) $workspace->getKey()));

        $this->demoSignals($workspace->getKey());
        $this->demoCommerce($workspace->getKey());
        $this->demoLeads($workspace->getKey());
        $this->demoCreative($workspace->getKey());
        $this->demoTasks($workspace->getKey());

        $actor->clear();

        $this->command?->info('Demo workspace seeded. Sign in with any '.
            'demo address and the password "'.self::PASSWORD.'".');
        $this->command?->warn('Performance figures are SANDBOX fixtures, not real spend.');
    }

    /**
     * Connect two providers in sandbox mode and sync them.
     *
     * Uses the real connection, binding and sync paths rather than writing rows
     * directly, so the demo data is produced by the same code a live account would
     * use — and so a defect in that path breaks the demo loudly instead of hiding
     * behind hand-written fixtures.
     */
    private function sandboxPerformance(mixed $workspaceId): void
    {
        $connections = app(ProviderConnectionRepository::class);
        $sync = app(SyncBoundAccount::class);

        /** @var Project|null $project */
        $project = Project::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ProjectStatus::Active->value)
            ->orderBy('created_at')
            ->first();

        if ($project === null) {
            return;
        }

        $accounts = [
            Provider::Meta->value => 'act_sandbox_1001',
            Provider::GoogleAds->value => '7788990011',
            Provider::TikTok->value => '7100000000000000001',
        ];

        foreach ($accounts as $providerValue => $externalId) {
            $provider = Provider::from($providerValue);

            // Every required credential is the sandbox token. A connection with
            // one real secret among them is a configuration mistake, not a
            // sandbox, and the adapter treats it as such.
            $credentials = [];

            foreach ($provider->requiredCredentialKeys() as $key) {
                $credentials[$key] = Credentials::SANDBOX_TOKEN;
            }

            $existing = $connections->findForProvider(new WorkspaceId((string) $workspaceId), $provider);

            if ($existing instanceof ProviderConnection) {
                $existing->replaceCredentials(new Credentials($credentials));
                $connection = $existing;
            } else {
                $connection = ProviderConnection::register(
                    id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
                    workspaceId: new WorkspaceId((string) $workspaceId),
                    provider: $provider,
                    name: 'SANDBOX — '.$provider->label(),
                    credentials: new Credentials($credentials),
                );
            }

            $connections->save($connection);

            /** @var ExternalAccount $account */
            $account = ExternalAccount::query()->updateOrCreate(
                ['provider_connection_id' => $connection->id->value, 'external_id' => $externalId],
                [
                    'workspace_id' => $workspaceId,
                    'provider' => $provider->value,
                    'name' => 'SANDBOX — '.$provider->label(),
                    'currency' => 'SAR',
                    'timezone' => 'Asia/Riyadh',
                    'status' => 'active',
                    'discovered_at' => now(),
                ],
            );

            // Binding is what grants the project visibility of the account. Without
            // it the sync skips the account entirely, which is the behaviour under
            // test elsewhere.
            ProjectIntegrationBinding::query()->updateOrCreate(
                ['project_id' => $project->getKey(), 'external_account_id' => $account->getKey()],
                ['workspace_id' => $workspaceId, 'sync_enabled' => true, 'bound_at' => now()],
            );

            $outcome = $sync((string) $workspaceId, (string) $account->getKey());

            $this->command?->line(sprintf(
                '  %s: %s, %d rows written.',
                $provider->label(),
                $outcome->status->value,
                $outcome->recordsWritten,
            ));
        }
    }

    /** @param array{name: string, email: string, role: SystemRole} $person */
    private function user(array $person): User
    {
        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['email' => $person['email']],
            [
                'name' => $person['name'],
                'password' => self::PASSWORD,
                'locale' => 'en',
                'timezone' => 'Asia/Riyadh',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        return $user;
    }

    private function clients(mixed $workspaceId, mixed $ownerId): void
    {
        $definitions = [
            ['name' => 'DEMO — Alhambra Retail', 'industry' => 'Retail', 'status' => ClientStatus::Active],
            ['name' => 'DEMO — Najd Fintech', 'industry' => 'Financial services', 'status' => ClientStatus::Active],
            ['name' => 'DEMO — Rawdah Hospitality', 'industry' => 'Hospitality', 'status' => ClientStatus::Prospect],
        ];

        foreach ($definitions as $definition) {
            /** @var Client $client */
            $client = Client::query()->updateOrCreate(
                ['workspace_id' => $workspaceId, 'slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'status' => $definition['status']->value,
                    'industry' => $definition['industry'],
                    'default_currency' => 'SAR',
                    'is_demo' => true,
                    'created_by' => $ownerId,
                ],
            );

            if ($definition['status'] !== ClientStatus::Active) {
                continue;
            }

            foreach (['Always-on performance', 'Seasonal campaign'] as $index => $projectName) {
                $name = 'DEMO — '.$projectName;

                Project::query()->updateOrCreate(
                    ['workspace_id' => $workspaceId, 'slug' => Str::slug($client->getAttribute('name').' '.$projectName)],
                    [
                        'client_id' => $client->getKey(),
                        'name' => $name,
                        'status' => $index === 0 ? ProjectStatus::Active->value : ProjectStatus::Planning->value,
                        'currency' => 'SAR',
                        'is_demo' => true,
                        'created_by' => $ownerId,
                    ],
                );
            }
        }
    }

    /**
     * A GA4 property in sandbox, synced, so the demo has counted taps to sit beside the leads.
     *
     * The fixtures carry `whatsapp_click`, `form_start` and `generate_lead` — the three events the
     * Lead Center's right-hand column is made of — and every row the sync writes is labelled
     * `sandbox`, so none of it can be mistaken for a client's measurement.
     */
    private function demoSignals(mixed $workspaceId): void
    {
        /** @var Client|null $client */
        $client = Client::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('created_at')
            ->first();

        if ($client === null) {
            return;
        }

        $connections = app(ProviderConnectionRepository::class);
        $provider = Provider::GoogleAnalytics;

        $credentials = [];

        foreach ($provider->requiredCredentialKeys() as $key) {
            $credentials[$key] = Credentials::SANDBOX_TOKEN;
        }

        $existing = $connections->findForProvider(new WorkspaceId((string) $workspaceId), $provider);

        if ($existing instanceof ProviderConnection) {
            $existing->replaceCredentials(new Credentials($credentials));
            $connection = $existing;
        } else {
            $connection = ProviderConnection::register(
                id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
                workspaceId: new WorkspaceId((string) $workspaceId),
                provider: $provider,
                name: 'SANDBOX — '.$provider->label(),
                credentials: new Credentials($credentials),
            );
        }

        $connections->save($connection);

        $source = app(SaveMeasurementSource::class)(new MeasurementSourceInput(
            clientId: (string) $client->getKey(),
            kind: MeasurementSourceKind::Analytics,
            externalId: 'properties/123456',
            name: 'SANDBOX — Demo GA4 property',
            provider: $provider,
            providerConnectionId: $connection->id->value,
        ));

        $result = app(SyncMeasurementSource::class)($source->id);

        $this->command?->line(sprintf(
            '  Measurement: %s, %d events observed.',
            $result->status->value,
            $result->rowsWritten,
        ));
    }

    /**
     * A pipeline with something in it.
     *
     * Deliberately uneven. Several leads nobody has contacted, so the "not yet" column has something
     * to say; a disqualification with its reason, because the server refuses one without; manual
     * entries, so the "not measured" marker appears where it matters; and leads carrying no campaign
     * at all, which is the commonest case in this market and the reason the unattributed row exists.
     *
     * Every one goes through `RecordLead`, so the demo data is produced by the code a live account
     * uses — and a defect in that path breaks the demo loudly instead of hiding behind fixtures.
     */
    private function demoLeads(mixed $workspaceId): void
    {
        /** @var list<Client> $clients */
        $clients = Client::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('created_at')
            ->get()
            ->all();

        if ($clients === []) {
            return;
        }

        $record = app(RecordLead::class);
        $change = app(ChangeLeadStage::class);
        $now = new DateTimeImmutable('now');

        /*
         * One currency throughout. Mixed currencies are a case the Lead Center handles by withholding
         * its totals, and a demo that triggered it would show four dashes where the figures should be.
         */
        $definitions = [
            ['days' => 1, 'kind' => LeadSourceKind::FormSubmission, 'detail' => 'Contact form', 'name' => 'Layla Al-Harbi', 'phone' => '0555001101', 'campaign' => 'autumn-range', 'source' => 'meta', 'value' => 250_000, 'stage' => null, 'reason' => null],
            ['days' => 2, 'kind' => LeadSourceKind::WhatsAppMessage, 'detail' => 'WhatsApp business', 'name' => 'Omar Al-Qahtani', 'phone' => '0555001102', 'campaign' => null, 'source' => null, 'value' => null, 'stage' => null, 'reason' => null],
            ['days' => 3, 'kind' => LeadSourceKind::PhoneCall, 'detail' => 'Showroom line', 'name' => 'Noura Al-Shehri', 'phone' => '0555001103', 'campaign' => null, 'source' => null, 'value' => 90_000, 'stage' => LeadStage::Contacted, 'reason' => null],
            ['days' => 5, 'kind' => LeadSourceKind::FormSubmission, 'detail' => 'Contact form', 'name' => 'Faisal Al-Dosari', 'phone' => '0555001104', 'campaign' => 'autumn-range', 'source' => 'meta', 'value' => 180_000, 'stage' => LeadStage::Qualified, 'reason' => null],
            ['days' => 6, 'kind' => LeadSourceKind::WhatsAppMessage, 'detail' => 'WhatsApp business', 'name' => 'Hind Al-Otaibi', 'phone' => '0555001105', 'campaign' => 'ramadan-teaser', 'source' => 'google', 'value' => 420_000, 'stage' => LeadStage::Won, 'reason' => null],
            ['days' => 8, 'kind' => LeadSourceKind::FormSubmission, 'detail' => 'Pricing page', 'name' => 'Saud Al-Ghamdi', 'phone' => '0555001106', 'campaign' => 'ramadan-teaser', 'source' => 'google', 'value' => 310_000, 'stage' => LeadStage::Won, 'reason' => null],
            ['days' => 9, 'kind' => LeadSourceKind::Manual, 'detail' => 'Referral from a partner', 'name' => 'Abdulrahman Al-Zahrani', 'phone' => '0555001107', 'campaign' => null, 'source' => null, 'value' => 150_000, 'stage' => LeadStage::Won, 'reason' => null],
            ['days' => 11, 'kind' => LeadSourceKind::PhoneCall, 'detail' => 'Showroom line', 'name' => 'Maha Al-Juhani', 'phone' => '0555001108', 'campaign' => null, 'source' => null, 'value' => null, 'stage' => LeadStage::Lost, 'reason' => null],
            ['days' => 13, 'kind' => LeadSourceKind::FormSubmission, 'detail' => 'Contact form', 'name' => 'Turki Al-Mutairi', 'phone' => '0555001109', 'campaign' => 'autumn-range', 'source' => 'meta', 'value' => null, 'stage' => LeadStage::Disqualified, 'reason' => 'Outside the delivery area'],
            ['days' => 16, 'kind' => LeadSourceKind::Import, 'detail' => 'Trade show list', 'name' => 'Reem Al-Harthi', 'phone' => '0555001110', 'campaign' => null, 'source' => null, 'value' => null, 'stage' => LeadStage::Lost, 'reason' => null],
            ['days' => 18, 'kind' => LeadSourceKind::WhatsAppMessage, 'detail' => 'WhatsApp business', 'name' => 'Khalid Al-Subaie', 'phone' => '0555001111', 'campaign' => 'ramadan-teaser', 'source' => 'google', 'value' => 200_000, 'stage' => LeadStage::Qualified, 'reason' => null],
            ['days' => 21, 'kind' => LeadSourceKind::FormSubmission, 'detail' => 'Contact form', 'name' => 'Ghada Al-Amri', 'phone' => '0555001112', 'campaign' => null, 'source' => null, 'value' => null, 'stage' => null, 'reason' => null],
        ];

        $recorded = 0;

        foreach ($clients as $index => $client) {
            /** @var Project|null $project */
            $project = Project::query()
                ->where('client_id', $client->getKey())
                ->orderBy('created_at')
                ->first();

            // The second client gets the shorter half, so the two do not look identical.
            $slice = $index === 0 ? $definitions : array_slice($definitions, 0, 5);

            foreach ($slice as $position => $definition) {
                $lead = $record(new LeadInput(
                    clientId: (string) $client->getKey(),
                    sourceKind: $definition['kind'],
                    receivedAt: $now->modify('-'.$definition['days'].' days'),
                    // What the lead itself carried. Null stays null: most enquiries carry nothing,
                    // and inventing a campaign for them is how an unattributed row disappears.
                    attribution: OrderAttribution::from(
                        source: $definition['source'],
                        medium: $definition['source'] === null ? null : 'cpc',
                        campaign: $definition['campaign'],
                    ),
                    projectId: $project === null ? null : (string) $project->getKey(),
                    sourceDetail: $definition['detail'],
                    name: $definition['name'],
                    // Unique per client, so the deduplication window does not merge the two sets.
                    phone: '055'.str_pad((string) ($index * 100 + $position + 5000), 7, '0', STR_PAD_LEFT),
                    valueMinor: $definition['value'],
                    currency: $definition['value'] === null ? null : 'SAR',
                ));

                $recorded += 1;

                if ($definition['stage'] === null) {
                    continue;
                }

                /*
                 * Moved through the stages one at a time, because the domain refuses an invalid
                 * transition — which is the behaviour being relied on, not worked around.
                 */
                foreach ($this->pathTo($definition['stage']) as $stage) {
                    $change(
                        $lead->id,
                        $stage,
                        $stage === LeadStage::Disqualified ? $definition['reason'] : null,
                    );
                }
            }
        }

        $this->command?->line(sprintf('  Leads: %d recorded across %d clients.', $recorded, count($clients)));
    }

    /**
     * The stages a lead has to pass through to reach one.
     *
     * The domain refuses a jump from new straight to won, deliberately: a lead that was never
     * contacted and never qualified cannot have been won, and a seeder allowed to skip would be
     * creating records the product's own rules say are impossible.
     *
     * @return list<LeadStage>
     */
    private function pathTo(LeadStage $target): array
    {
        return match ($target) {
            LeadStage::Contacted => [LeadStage::Contacted],
            LeadStage::Disqualified => [LeadStage::Contacted, LeadStage::Disqualified],
            LeadStage::Qualified => [LeadStage::Contacted, LeadStage::Qualified],
            LeadStage::Won => [LeadStage::Contacted, LeadStage::Qualified, LeadStage::Won],
            LeadStage::Lost => [LeadStage::Contacted, LeadStage::Qualified, LeadStage::Lost],
            default => [],
        };
    }

    /**
     * A Salla storefront in sandbox, synced, so the commerce and attribution surfaces have orders.
     *
     * The honest part of this demo is what it fails to match. One fixture order carries parameters
     * naming a campaign that exists in the sandbox ad account, so a matched row with a real ROAS
     * appears; the others carry a coupon, a bare click identifier, and nothing at all. That is roughly
     * what a real account looks like, and it exercises every branch of the attribution join rather than
     * the flattering one.
     */
    private function demoCommerce(mixed $workspaceId): void
    {
        /** @var Client|null $client */
        $client = Client::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('created_at')
            ->first();

        if ($client === null) {
            return;
        }

        $connections = app(ProviderConnectionRepository::class);
        $provider = Provider::Salla;
        $credentials = [];

        foreach ($provider->requiredCredentialKeys() as $key) {
            $credentials[$key] = Credentials::SANDBOX_TOKEN;
        }

        $existing = $connections->findForProvider(new WorkspaceId((string) $workspaceId), $provider);

        if ($existing instanceof ProviderConnection) {
            $existing->replaceCredentials(new Credentials($credentials));
            $connection = $existing;
        } else {
            $connection = ProviderConnection::register(
                id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
                workspaceId: new WorkspaceId((string) $workspaceId),
                provider: $provider,
                name: 'SANDBOX — '.$provider->label(),
                credentials: new Credentials($credentials),
            );
        }

        $connections->save($connection);

        $externalId = '1183363243';

        /** @var Store $store */
        $store = Store::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'provider' => $provider->value, 'external_id' => $externalId],
            [
                'client_id' => $client->getKey(),
                'provider_connection_id' => $connection->id->value,
                'name' => 'SANDBOX — Noon Retail storefront',
                'currency' => 'SAR',
                'timezone' => 'Asia/Riyadh',
            ],
        );

        $result = app(SyncStore::class)(
            (string) $workspaceId,
            (string) $store->getKey(),
            $provider,
            $externalId,
        );

        $this->command?->line(sprintf(
            '  Commerce: %s, %d orders written.',
            $result->status->value,
            $result->ordersWritten,
        ));
    }

    /**
     * A creative board with work on it, in every state that matters.
     *
     * Built through the real use cases, so the demo exercises the rules rather than bypassing them: an
     * approval attaches to a version, a rejection carries its reason, and a version added after an approval
     * sends the work back for review. The honest part is what the specification checks say — one piece of work
     * is checked against a Snapchat specification read from Snapchat's own developer documentation, and the
     * rest report "no spec recorded", which is what most placements genuinely are.
     */
    private function demoCreative(mixed $workspaceId): void
    {
        /** @var list<Client> $clients */
        $clients = Client::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('created_at')
            ->get()
            ->all();

        $client = $clients[0] ?? null;

        if ($client === null) {
            return;
        }

        /** @var Project|null $project */
        $project = Project::query()->where('client_id', $client->getKey())->orderBy('created_at')->first();

        $open = app(OpenCreativeRequest::class);
        $move = app(MoveCreativeRequest::class);
        $addVersion = app(AddCreativeVersion::class);
        $decide = app(DecideOnVersion::class);
        $comment = app(CommentOnRequest::class);

        $brief = static fn (string $title, AssetKind $kind, ?string $provider, ?string $placement, int $dueInDays): CreativeRequestInput => new CreativeRequestInput(
            clientId: (string) $client->getKey(),
            title: $title,
            kind: $kind,
            projectId: $project === null ? null : (string) $project->getKey(),
            brief: 'SANDBOX brief. Produced for the demo workspace, not for a client.',
            provider: $provider,
            placement: $placement,
            dueAt: (new DateTimeImmutable('now'))->modify('+'.$dueInDays.' days'),
        );

        // 1. Approved work, checked against a recorded Snapchat specification and passing.
        $approved = $open($brief('Ramadan Story preview', AssetKind::Image, 'snapchat', 'story_ad_preview_image', 6));
        $move($approved->id, CreativeState::Briefed);
        $move($approved->id, CreativeState::InProduction);
        $withVersion = $addVersion($approved->id, new VersionInput(
            kind: AssetKind::Image,
            measurements: new AssetMeasurements(widthPx: 360, heightPx: 600, fileSizeBytes: 740_000, format: 'png'),
            label: 'Cut 1',
            storagePath: 'sandbox/creative/ramadan-story-v1.png',
        ));
        $move($approved->id, CreativeState::InReview);
        $version = $withVersion->latestVersion();

        if ($version !== null) {
            $decide($version->id, ApprovalOutcome::Approved);
        }

        $comment($approved->id, new CommentInput(
            body: 'Signed off on the call. Keep this framing for the follow-up.',
            visibility: CommentVisibility::Client,
        ));

        // 2. Work sent back, with a reason — and a failing check that says which number is wrong.
        $rejected = $open($brief('App end card', AssetKind::Image, 'snapchat', 'app_end_card', 3));
        $move($rejected->id, CreativeState::Briefed);
        $move($rejected->id, CreativeState::InProduction);
        $withRejected = $addVersion($rejected->id, new VersionInput(
            kind: AssetKind::Image,
            // Too small and too heavy for the recorded specification, deliberately.
            measurements: new AssetMeasurements(widthPx: 640, heightPx: 1138, fileSizeBytes: 2_400_000, format: 'png'),
            label: 'Cut 1',
            storagePath: 'sandbox/creative/end-card-v1.png',
        ));
        $move($rejected->id, CreativeState::InReview);
        $rejectedVersion = $withRejected->latestVersion();

        if ($rejectedVersion !== null) {
            $decide($rejectedVersion->id, ApprovalOutcome::ChangesRequested, 'Below the minimum width, and over the file size limit.');
        }

        $comment($rejected->id, new CommentInput(
            body: 'Export at 1080 wide and compress harder before the next review.',
            visibility: CommentVisibility::Internal,
        ));

        // 3. Work on a placement nobody has a specification for, so the check honestly says so.
        $unchecked = $open($brief('Reels launch film', AssetKind::Video, 'meta', 'reels', 9));
        $move($unchecked->id, CreativeState::Briefed);
        $move($unchecked->id, CreativeState::InProduction);
        $addVersion($unchecked->id, new VersionInput(
            kind: AssetKind::Video,
            measurements: new AssetMeasurements(
                widthPx: 1080,
                heightPx: 1920,
                fileSizeBytes: 18_400_000,
                durationSeconds: 21.5,
                format: 'mp4',
            ),
            label: 'Rough cut',
            storagePath: 'sandbox/creative/reels-launch-v1.mp4',
        ));

        // 4. A brief nobody has started, and one whose file could not be measured at all.
        $open($brief('Eid carousel concepts', AssetKind::Carousel, null, null, 14));

        $unmeasured = $open($brief('Headline options', AssetKind::Copy, 'snapchat', 'headline', 2));
        $move($unmeasured->id, CreativeState::Briefed);
        $move($unmeasured->id, CreativeState::InProduction);
        $addVersion($unmeasured->id, new VersionInput(
            kind: AssetKind::Copy,
            // Thirty-eight characters, against a recorded maximum of thirty-four.
            measurements: new AssetMeasurements(textLength: 38),
            label: 'Option A',
            body: 'Everything you need, before Ramadan ends',
        ));

        $this->command?->line('  Creative: 5 requests briefed, one approved, one sent back with a reason.');
    }

    /**
     * An operations board with the shapes that matter on it.
     *
     * Built through the real use cases, so the demo exercises the rules rather than bypassing them: a
     * blocked task carries its reason, a cancelled one carries its own, and done work carries the moment it
     * was finished. The honest part is what the board is *missing* — two of these tasks have no due date and
     * one has nobody on it, because that is what a real board looks like, and the figures beside it are the
     * ones that say so rather than reporting a tidy pipeline.
     */
    private function demoTasks(mixed $workspaceId): void
    {
        /** @var Client|null $client */
        $client = Client::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', ClientStatus::Active->value)
            ->orderBy('created_at')
            ->first();

        if ($client === null) {
            return;
        }

        /** @var Project|null $project */
        $project = Project::query()->where('client_id', $client->getKey())->orderBy('created_at')->first();

        /** @var User|null $buyer */
        $buyer = User::query()->where('email', self::PEOPLE[2]['email'])->first();
        /** @var User|null $creative */
        $creative = User::query()->where('email', self::PEOPLE[3]['email'])->first();

        $open = app(OpenTask::class);
        $move = app(MoveTask::class);

        $brief = static fn (
            string $title,
            TaskPriority $priority,
            ?int $dueInDays,
            ?User $assignee,
        ): TaskInput => new TaskInput(
            title: $title,
            priority: $priority,
            description: 'SANDBOX task. Created for the demo workspace.',
            clientId: (string) $client->getKey(),
            projectId: $project === null ? null : (string) $project->getKey(),
            assigneeId: $assignee === null ? null : (string) $assignee->getKey(),
            dueOn: $dueInDays === null
                ? null
                : (new DateTimeImmutable('today'))->modify($dueInDays.' days'),
        );

        // Overdue and urgent: the card the board exists to put in front of somebody.
        $late = $open($brief('Rebuild the Ramadan audience lists', TaskPriority::Urgent, -3, $buyer));
        $move($late->id, TaskState::InProgress);

        // Blocked, with the reason that makes it actionable by somebody else.
        $blocked = $open($brief('Get the pixel onto the checkout page', TaskPriority::High, 2, $buyer));
        $move($blocked->id, TaskState::InProgress);
        $move($blocked->id, TaskState::Blocked, "Waiting on the client's developer for template access.");

        // Finished, so the board has a completion rate with a denominator that exists.
        $done = $open($brief('Draft the Eid media plan', TaskPriority::Normal, -8, $buyer));
        $move($done->id, TaskState::InProgress);
        $move($done->id, TaskState::Done);

        // In review: work sitting with somebody else, which the board counts separately.
        $review = $open($brief('Second pass on the story copy', TaskPriority::Normal, 1, $creative));
        $move($review->id, TaskState::InProgress);
        $move($review->id, TaskState::InReview);

        /*
         * Two undated tasks and one with nobody on it. Deliberately: these are the rows that make the
         * board's own honesty figures non-trivial, and a demo of tidy work would demonstrate nothing.
         */
        $open($brief('Write up the attribution caveats for the monthly deck', TaskPriority::Normal, null, $creative));
        $open($brief('Audit the UTM naming on last quarter\'s campaigns', TaskPriority::Low, null, null));

        // Cancelled, with its reason, so the completion rate has something to be a share of.
        $dropped = $open($brief('Set up the TikTok Spark Ads handoff', TaskPriority::Low, 5, $buyer));
        $move($dropped->id, TaskState::Cancelled, 'The client postponed the TikTok activity to next quarter.');

        $this->command?->line('  Operations: 7 tasks, one overdue, one blocked with a reason, two undated.');
    }
}
