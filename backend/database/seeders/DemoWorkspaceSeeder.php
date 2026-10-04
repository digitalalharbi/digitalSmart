<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\SystemRole;
use App\Domain\Client\ClientStatus;
use App\Domain\Project\ProjectStatus;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A workspace for local development and demos.
 *
 * Everything it creates is labelled DEMO and flagged `is_demo`, and it refuses
 * to run in production. No performance figures are invented here: clients and
 * projects exist, but there is no fabricated spend, and there will not be —
 * metrics arrive only from real provider syncs.
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

        $this->command?->info('Demo workspace seeded. Sign in with any '.
            'demo address and the password "'.self::PASSWORD.'".');
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
