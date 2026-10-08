<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Leads\Contract\LeadOwnerDirectory;
use App\Application\Leads\DTO\LeadOwnerCandidate;
use App\Domain\Access\Permission;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The three conditions, in SQL, against the same tables every read uses.
 *
 * Deliberately the same shape as `PermissionAwareRecipientResolver`, which is where the rule is explained — and
 * the two are pinned to each other by a test: somebody this directory offers must be somebody the assignment
 * notification would actually reach. If they ever disagreed, the owner column would name a person who was never
 * told and cannot open the record.
 *
 * `projects.view_all` is the unrestricted case; everybody else is narrowed to the clients of the projects they
 * are assigned to. A lead always has a client — the schema requires one — so there is no "no project" branch to
 * handle here, unlike a task.
 */
final readonly class EloquentLeadOwnerDirectory implements LeadOwnerDirectory
{
    /** @return list<LeadOwnerCandidate> */
    public function peopleWhoCanSee(string $workspaceId, string $clientId): array
    {
        $rows = DB::table('workspace_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)
            /* Active only: a suspended member keeps their row and loses everything else. */
            ->where('m.status', MembershipStatus::Active->value)
            ->whereExists(function (Builder $query): void {
                $query->select(DB::raw('1'))
                    ->from('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->whereColumn('pr.role_id', 'm.role_id')
                    ->where('p.slug', Permission::LeadsView->value);
            })
            ->where(function (Builder $scope) use ($workspaceId, $clientId): void {
                $scope->whereExists(function (Builder $query): void {
                    $query->select(DB::raw('1'))
                        ->from('permission_role as pr')
                        ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                        ->whereColumn('pr.role_id', 'm.role_id')
                        ->where('p.slug', Permission::ProjectsViewAll->value);
                })->orWhereExists(function (Builder $query) use ($workspaceId, $clientId): void {
                    $query->select(DB::raw('1'))
                        ->from('project_members as pm')
                        ->join('projects as pj', 'pj.id', '=', 'pm.project_id')
                        ->whereColumn('pm.user_id', 'm.user_id')
                        ->where('pj.workspace_id', $workspaceId)
                        ->where('pj.client_id', $clientId);
                });
            })
            ->orderBy('u.name')
            ->get(['u.id', 'u.name']);

        return array_values($rows->map(static fn (object $row): LeadOwnerCandidate => new LeadOwnerCandidate(
            id: (string) $row->id,
            name: (string) $row->name,
        ))->all());
    }
}
