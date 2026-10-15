<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Project\Contract\ReadinessFacts;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentReadinessFacts implements ReadinessFacts
{
    public function project(string $workspaceId, string $projectId): ?array
    {
        $p = DB::table('projects')->where(['workspace_id' => $workspaceId, 'id' => $projectId])->whereNull('deleted_at')
            ->first(['id', 'name', 'client_id', 'status']);

        return $p === null ? null : [
            'id' => (string) $p->id, 'name' => (string) $p->name,
            'client_id' => (string) $p->client_id, 'status' => (string) $p->status,
        ];
    }

    public function approvedBudget(string $workspaceId, string $projectId, string $clientId, string $today): array
    {
        $budgets = DB::table('budgets')->where(['workspace_id' => $workspaceId, 'status' => 'approved'])
            ->where(static function ($q) use ($projectId, $clientId): void {
                $q->where('project_id', $projectId)->orWhere(static fn ($x) => $x->whereNull('project_id')->where('client_id', $clientId));
            });

        return [
            'any' => (clone $budgets)->exists(),
            'covers_today' => (clone $budgets)->where('period_end', '>=', $today)->where('period_start', '<=', $today)->exists(),
        ];
    }

    public function boundAccounts(string $workspaceId, string $projectId): array
    {
        $accounts = DB::table('project_integration_bindings as b')->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where(['b.workspace_id' => $workspaceId, 'b.project_id' => $projectId]);

        return [
            'bound' => (clone $accounts)->count(),
            'usable' => (clone $accounts)->where('a.status', 'active')->where('b.sync_enabled', true)->count(),
        ];
    }

    public function creative(string $workspaceId, string $projectId, string $clientId): array
    {
        $requests = DB::table('creative_requests')->where('workspace_id', $workspaceId)->where('client_id', $clientId)
            ->where(static fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'))
            ->where('state', '!=', 'cancelled');

        return [
            'briefed' => (clone $requests)->exists(),
            'approved' => (clone $requests)->where('state', 'approved')->exists(),
        ];
    }

    public function stuckApprovals(string $workspaceId, string $projectId, DateTimeImmutable $olderThan): int
    {
        return DB::table('approval_requests')
            ->where(['workspace_id' => $workspaceId, 'project_id' => $projectId, 'state' => 'pending'])
            ->where('created_at', '<', $olderThan)->count();
    }
}
