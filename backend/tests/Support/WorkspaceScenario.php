<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\SystemRole;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Role;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\Workspace;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Support\Str;

/**
 * Builds a realistic workspace for feature tests.
 *
 * Tests assert on behaviour through the real provisioning path, so a bug in
 * role/permission wiring fails the suite rather than being papered over by
 * hand-made fixtures.
 */
final readonly class WorkspaceScenario
{
    public function __construct(
        public Workspace $workspace,
        public User $owner,
    ) {}

    public static function create(?string $name = null): self
    {
        $provisioner = app(WorkspaceProvisioner::class);
        $provisioner->syncPermissionCatalogue();

        $owner = User::factory()->create();
        $name ??= 'Workspace '.Str::random(6);

        $workspace = $provisioner->provision(
            name: $name,
            slug: Str::slug($name).'-'.Str::lower(Str::random(5)),
            owner: $owner,
        );

        return new self($workspace, $owner);
    }

    /** Add a user to this workspace in the given system role. */
    public function addMember(SystemRole $role, MembershipStatus $status = MembershipStatus::Active): User
    {
        $user = User::factory()->create();

        WorkspaceMember::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => $this->role($role)->getKey(),
            'status' => $status->value,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function role(SystemRole $role): Role
    {
        /** @var Role $model */
        $model = Role::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('slug', $role->value)
            ->firstOrFail();

        return $model;
    }
}
