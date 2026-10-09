<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Access\Permission;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The three conditions, in SQL, against the same tables every read uses.
 *
 * Deliberately the same shape as `PermissionAwareRecipientResolver`, which is where the rule is explained — and
 * the two are pinned to each other by a test: somebody this directory offers must be somebody the assignment
 * notification would actually reach. If they ever disagreed, an owner column would name a person who was never
 * told and cannot open the record.
 *
 * `projects.view_all` is the unrestricted case; everybody else is narrowed to the clients of the projects they
 * are assigned to. A null client is work that belongs to no client — a task about the agency itself — and then
 * the permission alone decides, exactly as the notification resolver does when a draft names neither a project
 * nor a client.
 */
final readonly class EloquentOwnerDirectory implements OwnerDirectory
{
    /** @return list<OwnerCandidate> */
    public function peopleWhoCanSee(string $workspaceId, ?string $clientId, Permission $required): array
    {
        $query = DB::table('workspace_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)
            /* Active only: a suspended member keeps their row and loses everything else. */
            ->where('m.status', MembershipStatus::Active->value)
            ->whereExists(function (Builder $sub) use ($required): void {
                $sub->select(DB::raw('1'))
                    ->from('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->whereColumn('pr.role_id', 'm.role_id')
                    ->where('p.slug', $required->value);
            });

        if ($clientId !== null) {
            $query->where(function (Builder $scope) use ($workspaceId, $clientId): void {
                $scope->whereExists(function (Builder $sub): void {
                    $sub->select(DB::raw('1'))
                        ->from('permission_role as pr')
                        ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                        ->whereColumn('pr.role_id', 'm.role_id')
                        ->where('p.slug', Permission::ProjectsViewAll->value);
                })->orWhereExists(function (Builder $sub) use ($workspaceId, $clientId): void {
                    $sub->select(DB::raw('1'))
                        ->from('project_members as pm')
                        ->join('projects as pj', 'pj.id', '=', 'pm.project_id')
                        ->whereColumn('pm.user_id', 'm.user_id')
                        ->where('pj.workspace_id', $workspaceId)
                        ->where('pj.client_id', $clientId);
                });
            });
        }

        $rows = $query->orderBy('u.name')->get(['u.id', 'u.name']);

        return array_values($rows->map(static fn (object $row): OwnerCandidate => new OwnerCandidate(
            id: (string) $row->id,
            name: (string) $row->name,
        ))->all());
    }
}
