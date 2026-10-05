<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\SystemRole;
use App\Domain\Client\ClientStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Project\ProjectStatus;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
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
}
