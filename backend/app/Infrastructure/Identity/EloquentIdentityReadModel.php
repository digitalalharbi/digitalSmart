<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\Contract\IdentityReadModel;
use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Identity\DTO\WorkspaceSummary;
use App\Domain\Access\PermissionSet;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use stdClass;

final class EloquentIdentityReadModel implements IdentityReadModel
{
    public function authenticatedUser(UserId $userId, ?WorkspaceId $activeWorkspaceId): ?AuthenticatedUser
    {
        $user = DB::table('users')
            ->select(['id', 'name', 'email', 'locale', 'timezone', 'job_title', 'avatar_path'])
            ->where('id', $userId->value)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->first();

        if (! $user instanceof stdClass) {
            return null;
        }

        $workspaces = $this->workspacesFor($userId);
        $active = $this->resolveActive($workspaces, $activeWorkspaceId);

        return new AuthenticatedUser(
            id: (string) $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
            locale: (string) $user->locale,
            timezone: (string) $user->timezone,
            jobTitle: is_string($user->job_title) && $user->job_title !== '' ? $user->job_title : null,
            avatarUrl: $this->avatarUrl($user->avatar_path),
            workspaces: $workspaces,
            activeWorkspace: $active,
            permissions: $active === null
                ? []
                : $this->permissionsFor($userId, new WorkspaceId($active->id))->slugs(),
        );
    }

    public function defaultWorkspaceId(UserId $userId): ?WorkspaceId
    {
        $id = DB::table('workspace_members')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_members.workspace_id')
            ->where('workspace_members.user_id', $userId->value)
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->whereNull('workspaces.deleted_at')
            ->orderBy('workspace_members.joined_at')
            ->value('workspace_members.workspace_id');

        return is_string($id) ? new WorkspaceId($id) : null;
    }

    public function isMemberOf(UserId $userId, WorkspaceId $workspaceId): bool
    {
        return DB::table('workspace_members')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_members.workspace_id')
            ->where('workspace_members.user_id', $userId->value)
            ->where('workspace_members.workspace_id', $workspaceId->value)
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->whereNull('workspaces.deleted_at')
            ->exists();
    }

    /** @return list<WorkspaceSummary> */
    private function workspacesFor(UserId $userId): array
    {
        /** @var list<WorkspaceSummary> $summaries */
        $summaries = DB::table('workspace_members')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_members.workspace_id')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->select([
                'workspaces.id', 'workspaces.name', 'workspaces.slug',
                'workspaces.default_currency', 'workspaces.default_locale', 'workspaces.timezone',
                'roles.slug as role_slug', 'roles.name as role_name',
            ])
            ->where('workspace_members.user_id', $userId->value)
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->whereNull('workspaces.deleted_at')
            ->orderBy('workspaces.name')
            ->get()
            ->map(fn (stdClass $row): WorkspaceSummary => new WorkspaceSummary(
                id: (string) $row->id,
                name: (string) $row->name,
                slug: (string) $row->slug,
                roleSlug: (string) $row->role_slug,
                roleName: (string) $row->role_name,
                defaultCurrency: (string) $row->default_currency,
                defaultLocale: (string) $row->default_locale,
                timezone: (string) $row->timezone,
            ))
            ->values()
            ->all();

        return $summaries;
    }

    private function permissionsFor(UserId $userId, WorkspaceId $workspaceId): PermissionSet
    {
        /** @var list<string> $slugs */
        $slugs = DB::table('workspace_members')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('workspace_members.user_id', $userId->value)
            ->where('workspace_members.workspace_id', $workspaceId->value)
            ->where('workspace_members.status', MembershipStatus::Active->value)
            ->pluck('permissions.slug')
            ->all();

        return PermissionSet::fromSlugs($slugs);
    }

    /**
     * @param  list<WorkspaceSummary>  $workspaces
     */
    private function resolveActive(array $workspaces, ?WorkspaceId $activeWorkspaceId): ?WorkspaceSummary
    {
        if ($activeWorkspaceId !== null) {
            foreach ($workspaces as $workspace) {
                if ($workspace->id === $activeWorkspaceId->value) {
                    return $workspace;
                }
            }
        }

        // The requested workspace is not one of theirs: fall back to the first,
        // rather than granting a workspace they do not belong to.
        return $workspaces[0] ?? null;
    }

    private function avatarUrl(mixed $path): ?string
    {
        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }
}
