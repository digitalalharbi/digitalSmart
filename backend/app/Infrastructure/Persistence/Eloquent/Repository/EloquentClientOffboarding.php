<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Client\Contract\ClientOffboarding;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stops everything recurring, deletes nothing.
 *
 * Every statement here is an UPDATE. Metrics, campaigns, reports, leads, orders, attribution and the audit
 * trail are untouched — a client who has left is still the subject of last quarter's report, and the
 * figures in it must be the figures it was filed with.
 */
final readonly class EloquentClientOffboarding implements ClientOffboarding
{
    private const array OPEN_PROJECT = ['planning', 'onboarding', 'active', 'paused', 'closing'];

    public function preview(string $workspaceId, string $clientId): array
    {
        $projects = DB::table('projects')->where(['workspace_id' => $workspaceId, 'client_id' => $clientId])->whereNull('deleted_at');
        $projectIds = (clone $projects)->pluck('id')->all();

        return [
            'projects_closing' => (clone $projects)->whereIn('status', self::OPEN_PROJECT)->count(),
            'bindings_paused' => $projectIds === [] ? 0 : DB::table('project_integration_bindings')
                ->whereIn('project_id', $projectIds)->where('sync_enabled', true)->count(),
            'connections_disconnected' => DB::table('provider_connections')
                ->where(['workspace_id' => $workspaceId, 'owner_client_id' => $clientId])
                ->whereNull('deleted_at')->whereNotNull('credentials')->count(),
            'portal_suspended' => DB::table('client_portals')
                ->where(['client_id' => $clientId])->whereNull('suspended_at')->exists(),
            'share_links_revoked' => DB::table('report_shares as s')->join('reports as r', 'r.id', '=', 's.report_id')
                ->where('r.client_id', $clientId)->whereNull('s.revoked_at')->where('s.expires_at', '>', now())->count(),
            'open_tasks' => DB::table('tasks')->where(['workspace_id' => $workspaceId, 'client_id' => $clientId])
                ->whereNotIn('state', ['done', 'cancelled'])->count(),
            'open_requests' => DB::table('client_requests')->where(['workspace_id' => $workspaceId, 'client_id' => $clientId])
                ->whereIn('state', ['submitted', 'needs_information', 'accepted', 'in_progress'])->count(),
        ];
    }

    public function apply(string $workspaceId, string $clientId, string $userId, string $reason, DateTimeImmutable $at): array
    {
        return DB::transaction(function () use ($workspaceId, $clientId, $userId, $reason, $at): array {
            $summary = $this->preview($workspaceId, $clientId);
            $projectIds = DB::table('projects')->where(['workspace_id' => $workspaceId, 'client_id' => $clientId])
                ->whereNull('deleted_at')->pluck('id')->all();

            if ($projectIds !== []) {
                DB::table('project_integration_bindings')->whereIn('project_id', $projectIds)
                    ->update(['sync_enabled' => false, 'updated_at' => $at]);

                DB::table('projects')->whereIn('id', $projectIds)->whereIn('status', self::OPEN_PROJECT)
                    ->update(['status' => 'completed', 'updated_at' => $at]);
            }

            /* The client's own credentials are theirs: destroyed, while the accounts and history they found stay. */
            DB::table('provider_connections')
                ->where(['workspace_id' => $workspaceId, 'owner_client_id' => $clientId])->whereNull('deleted_at')
                ->update(['status' => 'revoked', 'credentials' => null, 'external_owner_id' => null, 'expires_at' => null,
                    'status_message' => 'Client offboarded.', 'updated_at' => $at]);

            DB::table('client_portals')->where('client_id', $clientId)->whereNull('suspended_at')
                ->update(['suspended_at' => $at, 'suspended_by' => $userId, 'suspended_reason' => $reason, 'updated_at' => $at]);

            DB::table('report_shares')
                ->whereIn('report_id', DB::table('reports')->select('id')->where('client_id', $clientId))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $at, 'revoked_reason' => $reason, 'updated_at' => $at]);

            return $summary;
        });
    }
}
