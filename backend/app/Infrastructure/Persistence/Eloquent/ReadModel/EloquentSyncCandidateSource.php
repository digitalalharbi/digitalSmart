<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Integration\Contract\SyncCandidateSource;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Sync\SyncCandidate;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentSyncCandidateSource implements SyncCandidateSource
{
    /** Project states that still have work in them — `ProjectStatus::isLive()` is the rule; SQL needs the list. */
    private const array LIVE_PROJECTS = ['planning', 'onboarding', 'active', 'paused', 'closing'];

    /** @return list<SyncCandidate> */
    public function candidates(?WorkspaceId $workspaceId = null): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('external_accounts as a')
            ->join('provider_connections as pc', 'pc.id', '=', 'a.provider_connection_id')
            ->whereExists(static fn ($q) => $q->from('project_integration_bindings as b')
                ->whereColumn('b.external_account_id', 'a.id')
                ->where('b.sync_enabled', true))
            ->when($workspaceId instanceof WorkspaceId, static fn ($q) => $q->where('a.workspace_id', $workspaceId?->value))
            ->selectRaw('a.id, a.workspace_id, a.provider')
            ->selectRaw('coalesce(pc.owner_client_id::text, (
                select min(p.client_id::text) from project_integration_bindings b
                join projects p on p.id = b.project_id where b.external_account_id = a.id), a.workspace_id::text) as fairness_key')
            ->selectRaw("(select max(sc.last_success_at) from sync_cursors sc
                where sc.external_account_id = a.id and sc.kind = 'insights') as last_success_at")
            ->selectRaw("(select count(*) from campaigns c where c.external_account_id = a.id and c.status = 'active') as active_campaigns")
            ->selectRaw('exists (select 1 from project_integration_bindings b join projects p on p.id = b.project_id
                where b.external_account_id = a.id and b.sync_enabled and p.deleted_at is null
                and p.status in (\''.implode("','", self::LIVE_PROJECTS).'\')) as has_live_project')
            ->orderBy('a.id')
            ->get()
            ->all();

        return array_map(static fn (stdClass $r): SyncCandidate => new SyncCandidate(
            workspaceId: (string) $r->workspace_id,
            externalAccountId: (string) $r->id,
            provider: Provider::from((string) $r->provider),
            fairnessKey: (string) $r->fairness_key,
            lastSuccessAt: is_string($r->last_success_at) ? new DateTimeImmutable($r->last_success_at) : null,
            activeCampaigns: (int) $r->active_campaigns,
            hasLiveProject: (bool) $r->has_live_project,
        ), $rows);
    }
}
