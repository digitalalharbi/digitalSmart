<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Leads\Automation\LeadAutomationRepository;
use App\Application\Leads\Automation\LeadAutomationSettings;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Access\Permission;
use App\Domain\Leads\Automation\AssignmentMode;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentLeadAutomationRepository implements LeadAutomationRepository
{
    public function settings(string $workspaceId, string $projectId): LeadAutomationSettings
    {
        $row = DB::table('lead_automation_settings')->where('workspace_id', $workspaceId)->where('project_id', $projectId)->first();

        if (! $row instanceof stdClass) {
            return LeadAutomationSettings::off($projectId);
        }

        return new LeadAutomationSettings(
            $projectId, (bool) $row->enabled, AssignmentMode::from((string) $row->assignment_mode),
            is_string($row->owner_id) ? $row->owner_id : null, (bool) $row->create_task,
            is_string($row->task_title_ar) ? $row->task_title_ar : null, is_string($row->task_title_en) ? $row->task_title_en : null,
            (string) $row->task_priority, (bool) $row->notify_owner, $row->sla_minutes === null ? null : (int) $row->sla_minutes,
        );
    }

    public function save(string $workspaceId, LeadAutomationSettings $settings, string $updatedBy): void
    {
        $values = [
            'workspace_id' => $workspaceId,
            'enabled' => $settings->enabled,
            'assignment_mode' => $settings->mode->value,
            'owner_id' => $settings->mode->needsOwner() ? $settings->ownerId : null,
            'create_task' => $settings->createTask,
            'task_title_ar' => $settings->taskTitleAr,
            'task_title_en' => $settings->taskTitleEn,
            'task_priority' => $settings->taskPriority,
            'notify_owner' => $settings->notifyOwner,
            'sla_minutes' => $settings->slaMinutes,
            'updated_by' => $updatedBy,
            'updated_at' => now(),
        ];

        DB::table('lead_automation_settings')->upsert(
            [[...$values, 'project_id' => $settings->projectId, 'created_at' => now()]],
            ['project_id'],
            array_keys($values),
        );
    }

    public function eligibleOwners(string $workspaceId, string $projectId): array
    {
        $rows = DB::table('workspace_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspaceId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereExists(fn (Builder $sub) => $this->hasPermission($sub, Permission::LeadsView))
            ->where(function (Builder $scope) use ($projectId): void {
                $scope->whereExists(fn (Builder $sub) => $this->hasPermission($sub, Permission::ProjectsViewAll))
                    ->orWhereExists(function (Builder $sub) use ($projectId): void {
                        $sub->select(DB::raw('1'))->from('project_members as pm')
                            ->whereColumn('pm.user_id', 'm.user_id')->where('pm.project_id', $projectId);
                    });
            })
            ->orderBy('u.id')
            ->get(['u.id', 'u.name']);

        return array_values($rows->map(static fn (object $row): OwnerCandidate => new OwnerCandidate((string) $row->id, (string) $row->name))->all());
    }

    public function nextInRotation(string $workspaceId, string $projectId): ?string
    {
        return DB::transaction(function () use ($workspaceId, $projectId): ?string {
            /* Make sure the pointer row exists, then take it for the length of the choice. */
            DB::table('lead_round_robin')->insertOrIgnore(['project_id' => $projectId, 'last_user_id' => null, 'updated_at' => now()]);
            $pointer = DB::table('lead_round_robin')->where('project_id', $projectId)->lockForUpdate()->first();

            $pool = array_map(static fn (OwnerCandidate $c): string => $c->id, $this->eligibleOwners($workspaceId, $projectId));

            if ($pool === []) {
                return null;
            }

            $last = $pointer instanceof stdClass && is_string($pointer->last_user_id) ? $pointer->last_user_id : null;
            /* The first id greater than the last one used, else around to the start. */
            $next = null;

            foreach ($pool as $id) {
                if ($last === null || strcmp($id, $last) > 0) {
                    $next = $id;
                    break;
                }
            }

            $next ??= $pool[0];

            DB::table('lead_round_robin')->where('project_id', $projectId)->update(['last_user_id' => $next, 'updated_at' => now()]);

            return $next;
        });
    }

    public function timezone(string $workspaceId): string
    {
        $zone = DB::table('workspaces')->where('id', $workspaceId)->value('timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    private function hasPermission(Builder $sub, Permission $permission): void
    {
        $sub->select(DB::raw('1'))->from('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->whereColumn('pr.role_id', 'm.role_id')->where('p.slug', $permission->value);
    }
}
