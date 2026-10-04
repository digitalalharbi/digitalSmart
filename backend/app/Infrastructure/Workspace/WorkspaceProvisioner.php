<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace;

use App\Domain\Access\Permission as PermissionEnum;
use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Permission;
use App\Infrastructure\Persistence\Eloquent\Model\Role;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Illuminate\Support\Facades\DB;

/**
 * Creates a workspace together with everything it cannot function without:
 * its system roles, their permissions, and an owner.
 *
 * Runs in a transaction — a workspace with no owner, or roles with no
 * permissions, would be a broken tenant that nobody could administer.
 */
final readonly class WorkspaceProvisioner
{
    public function provision(
        string $name,
        string $slug,
        User $owner,
        string $defaultCurrency = 'SAR',
        string $defaultLocale = 'en',
        string $timezone = 'UTC',
    ): Workspace {
        return DB::transaction(function () use ($name, $slug, $owner, $defaultCurrency, $defaultLocale, $timezone): Workspace {
            /** @var Workspace $workspace */
            $workspace = Workspace::query()->create([
                'name' => $name,
                'slug' => $slug,
                'default_currency' => strtoupper($defaultCurrency),
                'default_locale' => $defaultLocale,
                'timezone' => $timezone,
                'settings' => [],
            ]);

            $roles = $this->createSystemRoles($workspace);

            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->getKey(),
                'user_id' => $owner->getKey(),
                'role_id' => $roles[SystemRole::Owner->value]->getKey(),
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
            ]);

            return $workspace;
        });
    }

    /**
     * Create (or refresh) the system roles for a workspace.
     *
     * @return array<string, Role>
     */
    public function createSystemRoles(Workspace $workspace): array
    {
        /** @var array<string, string> $permissionIds slug => id */
        $permissionIds = Permission::query()->pluck('id', 'slug')->all();

        $roles = [];

        foreach (SystemRole::cases() as $systemRole) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['workspace_id' => $workspace->getKey(), 'slug' => $systemRole->value],
                [
                    'name' => $systemRole->label(),
                    'description' => $systemRole->description(),
                    'is_system' => true,
                ],
            );

            $ids = [];

            foreach ($systemRole->permissions() as $permission) {
                $id = $permissionIds[$permission->value] ?? null;

                if (is_string($id)) {
                    $ids[] = $id;
                }
            }

            // sync(), not attach(): re-provisioning must converge on the
            // definition in code, including removing permissions dropped from it.
            $role->permissions()->sync($ids);

            $roles[$systemRole->value] = $role;
        }

        return $roles;
    }

    /** Upsert the permission catalogue from the enum. */
    public function syncPermissionCatalogue(): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission->value],
                ['group' => $permission->group(), 'description' => $permission->description()],
            );
        }
    }
}
