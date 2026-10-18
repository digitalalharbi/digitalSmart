<?php

declare(strict_types=1);

namespace App\Infrastructure\Agents;

use App\Application\Agents\Contract\AgentActors;
use App\Domain\Access\Permission as PermissionEnum;
use App\Domain\Workspace\MembershipStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Permission;
use App\Infrastructure\Persistence\Eloquent\Model\Role;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use App\Infrastructure\Persistence\Eloquent\Model\WorkspaceMember;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The agent's seat in a workspace: a member with a role of its own, and no way to sign in.
 *
 * Its password is a random hash nobody holds, so the account cannot be used from the login screen; it exists only to
 * be bound as the acting user by a routine. The role is deliberately short — see `AgentActors`.
 */
final class EloquentAgentIdentity implements AgentActors
{
    public const SLUG = 'ai-agent';

    /** @var list<PermissionEnum> */
    private const PERMISSIONS = [
        PermissionEnum::AnalyticsView,
        PermissionEnum::BudgetsView,
        PermissionEnum::TrackingView,
        PermissionEnum::LeadsView,
        PermissionEnum::CreativeView,
        PermissionEnum::TasksView,
        PermissionEnum::TasksManage,
        PermissionEnum::ApprovalsView,
        PermissionEnum::ApprovalsRequest,
        PermissionEnum::ReportsView,
        PermissionEnum::ProjectsViewAll,
        PermissionEnum::ClientsView,
    ];

    public function ensure(string $workspaceId): string
    {
        $role = Role::query()->firstOrCreate(
            ['workspace_id' => $workspaceId, 'slug' => self::SLUG],
            ['name' => 'digitalSmart agent', 'description' => 'Reads, proposes and opens tasks. Cannot decide or carry out anything.', 'is_system' => false],
        );

        $ids = Permission::query()->whereIn('slug', array_map(static fn (PermissionEnum $p): string => $p->value, self::PERMISSIONS))->pluck('id')->all();
        $role->permissions()->sync($ids);

        $email = sprintf('agent+%s@agents.digitalsmart.invalid', $workspaceId);

        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'digitalSmart agent', 'password' => Hash::make(Str::random(64))],
        );

        WorkspaceMember::query()->firstOrCreate(
            ['workspace_id' => $workspaceId, 'user_id' => $user->getKey()],
            ['role_id' => $role->getKey(), 'status' => MembershipStatus::Active->value, 'joined_at' => now()],
        );

        return (string) $user->getKey();
    }
}
