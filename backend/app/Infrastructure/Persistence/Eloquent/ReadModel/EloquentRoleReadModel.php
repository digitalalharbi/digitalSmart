<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Workspace\Contract\RoleReadModel;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Support\Facades\DB;

final class EloquentRoleReadModel implements RoleReadModel
{
    public function rolesFor(WorkspaceId $workspaceId): array
    {
        $roles = DB::table('roles')
            ->where(function ($scope) use ($workspaceId): void {
                $scope->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId->value);
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'description', 'is_system']);

        $held = [];

        foreach (DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permission_role.role_id', $roles->pluck('id')->all())
            ->orderBy('permissions.slug')
            ->get(['permission_role.role_id as role_id', 'permissions.slug as permission_slug']) as $row) {
            $held[(string) $row->role_id][] = (string) $row->permission_slug;
        }

        $members = DB::table('workspace_members')
            ->where('workspace_id', $workspaceId->value)
            ->where('status', '<>', 'revoked')
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as total')
            ->pluck('total', 'role_id');

        $out = [];

        foreach ($roles as $role) {
            $out[] = [
                'slug' => (string) $role->slug,
                'name' => (string) $role->name,
                'description' => $role->description === null ? null : (string) $role->description,
                'is_system' => (bool) $role->is_system,
                'members' => (int) ($members[$role->id] ?? 0),
                'permissions' => $held[(string) $role->id] ?? [],
            ];
        }

        return $out;
    }

    public function catalogue(): array
    {
        $out = [];

        foreach (DB::table('permissions')->orderBy('group')->orderBy('slug')->get(['slug', 'group', 'description']) as $row) {
            $out[] = [
                'slug' => (string) $row->slug,
                'group' => (string) $row->group,
                'description' => (string) $row->description,
            ];
        }

        return $out;
    }
}
