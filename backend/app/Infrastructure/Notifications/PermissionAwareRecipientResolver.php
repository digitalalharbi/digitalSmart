<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\RecipientResolver;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Domain\Access\Permission;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may be told, resolved in SQL against the same rules every read follows.
 *
 * The rule is one sentence: **a person may be told about something only if they could have read it.** In
 * practice that is three conditions, and leaving any of them out leaks something:
 *
 *  1. **Active membership of this workspace.** A suspended or removed member is not told. Otherwise somebody
 *     whose access was revoked keeps receiving a running commentary on the client they were removed from.
 *  2. **The permission the kind requires**, resolved through their role — plus a second permission where the
 *     kind names one. An approval for a spend change goes only to people who could actually decide it:
 *     telling five people when three of them cannot approve it is how a bell comes to be ignored.
 *  3. **Visibility of what it is about.** Somebody with `projects.view_all` sees everything; everybody else is
 *     narrowed to the projects they are assigned to, with the same "a request with no project reaches people
 *     through its client" rule the boards use.
 *
 * A notification is a disclosure — "Raise the Ramadan daily budget is waiting for your decision" names a
 * client, a campaign and an intention. Every one of those conditions is the reason this class exists rather
 * than a `WorkspaceMember::all()`.
 */
final readonly class PermissionAwareRecipientResolver implements RecipientResolver
{
    public function resolve(string $workspaceId, NotificationDraft $draft): array
    {
        if ($draft->onlyUserId !== null) {
            /*
             * A single named recipient — an assignment. Still checked: a task assigned to somebody who cannot
             * see the board would be a notification they can read and a surface they cannot open.
             */
            return $this->holders($workspaceId, $draft, [$draft->onlyUserId]);
        }

        return $this->holders($workspaceId, $draft, null);
    }

    /**
     * Members of this workspace who hold the required permissions and can see the subject.
     *
     * @param  list<string>|null  $limitTo
     * @return list<string>
     */
    private function holders(string $workspaceId, NotificationDraft $draft, ?array $limitTo): array
    {
        $required = [$draft->kind->requires()->value];

        if ($draft->alsoRequires !== null) {
            $required[] = $draft->alsoRequires;
        }

        $builder = DB::table('workspace_members as m')
            ->where('m.workspace_id', $workspaceId)
            // Active only. A suspended member keeps their row and loses their notifications.
            ->where('m.status', MembershipStatus::Active->value);

        if ($limitTo !== null) {
            if ($limitTo === []) {
                return [];
            }

            $builder->whereIn('m.user_id', $limitTo);
        }

        foreach ($required as $permission) {
            /*
             * One existence check per permission rather than a join and a count. A member must hold *all* of
             * them, and a `whereIn` with a count is the version that quietly passes somebody holding one of two.
             */
            $builder->whereExists(function (Builder $query) use ($permission): void {
                $query->select(DB::raw('1'))
                    ->from('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->whereColumn('pr.role_id', 'm.role_id')
                    ->where('p.slug', $permission);
            });
        }

        $subjectProject = $draft->aboutProjectId;
        $subjectClient = $draft->aboutClientId;

        if ($subjectProject !== null || $subjectClient !== null) {
            $builder->where(function (Builder $scope) use (
                $workspaceId,
                $subjectProject,
                $subjectClient,
            ): void {
                /*
                 * Unrestricted readers first: somebody with `projects.view_all` sees every project, so they are
                 * told about everything their permission covers.
                 */
                $scope->whereExists(function (Builder $query): void {
                    $query->select(DB::raw('1'))
                        ->from('permission_role as pr')
                        ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                        ->whereColumn('pr.role_id', 'm.role_id')
                        ->where('p.slug', Permission::ProjectsViewAll->value);
                });

                if ($subjectProject !== null) {
                    $scope->orWhereExists(function (Builder $query) use ($subjectProject): void {
                        $query->select(DB::raw('1'))
                            ->from('project_members as pm')
                            ->whereColumn('pm.user_id', 'm.user_id')
                            ->where('pm.project_id', $subjectProject);
                    });
                }

                if ($subjectClient !== null) {
                    /*
                     * No project of its own: reached through the client, the same way the task board and the
                     * approval queue reach an unassigned record.
                     */
                    $scope->orWhereExists(function (Builder $query) use ($workspaceId, $subjectClient): void {
                        $query->select(DB::raw('1'))
                            ->from('project_members as pm')
                            ->join('projects as pj', 'pj.id', '=', 'pm.project_id')
                            ->whereColumn('pm.user_id', 'm.user_id')
                            ->where('pj.workspace_id', $workspaceId)
                            ->where('pj.client_id', $subjectClient);
                    });
                }
            });
        }

        /** @var list<string> */
        return $builder->orderBy('m.user_id')->pluck('m.user_id')->map(
            static fn (mixed $id): string => (string) $id,
        )->values()->all();
    }
}
