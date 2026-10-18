<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Activity\Contract\ActivityReadModel;
use App\Application\Activity\DTO\ActivityItem;
use App\Domain\Audit\AuditAction;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentActivityReadModel implements ActivityReadModel
{
    /** Business events only. Credentials, sign-ins, tags and sync toggles are the audit trail's, not the feed's. */
    private const array ACTIONS = [
        AuditAction::ClientCreated, AuditAction::ClientStatusChanged, AuditAction::ClientOffboarded,
        AuditAction::ProjectCreated, AuditAction::ProjectStatusChanged,
        AuditAction::CampaignProjectAssigned,
        AuditAction::BudgetCreated, AuditAction::BudgetApproved, AuditAction::BudgetAllocationsChanged,
        AuditAction::LeadStageChanged, AuditAction::LeadAssigned,
        AuditAction::CreativeRequested, AuditAction::CreativeDecided,
        AuditAction::TaskOpened, AuditAction::TaskAssigned,
        AuditAction::ApprovalRequested, AuditAction::ApprovalDecided, AuditAction::ApprovalEnacted,
        AuditAction::ClientRequestSubmitted, AuditAction::ClientRequestAnswered,
    ];

    /** How many entries are considered before scope narrows them — bounded, so the cost is not the audit table's. */
    private const int CANDIDATES = 400;

    /** Entity type → table holding its project and client. Campaigns resolve through their account's bindings. */
    private const array TABLES = [
        'budget' => 'budgets', 'task' => 'tasks', 'lead' => 'leads', 'approval_request' => 'approval_requests',
        'creative_request' => 'creative_requests', 'client_request' => 'client_requests',
    ];

    public function recent(string $workspaceId, ?array $visibleProjectIds, int $limit, ?string $onlyProjectId = null, ?string $onlyClientId = null): array
    {
        if ($visibleProjectIds === []) {
            return [];
        }

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.workspace_id', $workspaceId)
            ->whereIn('a.action', array_map(static fn (AuditAction $a): string => $a->value, self::ACTIONS))
            ->whereNotNull('a.auditable_id')
            ->orderByDesc('a.created_at')
            /* One project's or client's events are a small share of the agency's, so a scoped read looks further back. */
            ->limit($onlyProjectId !== null || $onlyClientId !== null ? self::CANDIDATES * 6 : self::CANDIDATES)
            ->get(['a.id', 'a.action', 'a.summary', 'a.created_at', 'a.auditable_type', 'a.auditable_id', 'u.name as actor_name']);

        /** @var array<string, list<string>> $idsByType */
        $idsByType = [];

        foreach ($rows as $row) {
            $idsByType[(string) $row->auditable_type][] = (string) $row->auditable_id;
        }

        $resolved = $this->resolve($workspaceId, $idsByType);
        $visibleClients = $visibleProjectIds === null ? null : array_flip($this->clientsOf($workspaceId, $visibleProjectIds));
        $visibleProjects = $visibleProjectIds === null ? null : array_flip($visibleProjectIds);

        $out = [];

        foreach ($rows as $row) {
            $key = $row->auditable_type.':'.$row->auditable_id;
            [$projectId, $clientId] = $resolved[$key] ?? [null, null];

            if (($onlyProjectId !== null && $onlyProjectId !== $projectId)
                || ($onlyClientId !== null && $onlyClientId !== $clientId)) {
                continue;
            }

            if ($visibleProjects !== null) {
                $inProject = $projectId !== null && isset($visibleProjects[$projectId]);
                $inClient = $projectId === null && $clientId !== null && isset($visibleClients[$clientId]);

                if (! $inProject && ! $inClient) {
                    continue;
                }
            }

            $out[] = new ActivityItem(
                id: (string) $row->id,
                action: (string) $row->action,
                sentence: is_string($row->summary) && $row->summary !== '' ? $row->summary : (string) $row->action,
                actorName: is_string($row->actor_name) ? $row->actor_name : null,
                entityType: (string) $row->auditable_type,
                entityId: (string) $row->auditable_id,
                projectId: $projectId,
                clientId: $clientId,
                at: new DateTimeImmutable((string) $row->created_at),
            );

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $idsByType
     * @return array<string, array{0: ?string, 1: ?string}> "type:id" => [project_id, client_id]
     */
    private function resolve(string $workspaceId, array $idsByType): array
    {
        $out = [];

        foreach ($idsByType as $type => $ids) {
            $ids = array_values(array_unique($ids));

            if ($type === 'project') {
                foreach (DB::table('projects')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->get(['id', 'client_id']) as $r) {
                    $out['project:'.$r->id] = [(string) $r->id, (string) $r->client_id];
                }
            } elseif ($type === 'client') {
                foreach ($ids as $id) {
                    $out['client:'.$id] = [null, $id];
                }
            } elseif ($type === 'campaign') {
                /* Placed explicitly, else through the projects its account is bound to (first, deterministic). */
                foreach (DB::table('campaigns as c')
                    ->leftJoin('project_integration_bindings as b', 'b.external_account_id', '=', 'c.external_account_id')
                    ->leftJoin('projects as p', 'p.id', '=', DB::raw('coalesce(c.project_id, b.project_id)'))
                    ->where('c.workspace_id', $workspaceId)->whereIn('c.id', $ids)
                    ->get(['c.id', 'p.id as project_id', 'p.client_id']) as $r) {
                    $out['campaign:'.$r->id] ??= [is_string($r->project_id) ? $r->project_id : null, is_string($r->client_id) ? $r->client_id : null];
                }
            } elseif (isset(self::TABLES[$type])) {
                foreach (DB::table(self::TABLES[$type])->where('workspace_id', $workspaceId)->whereIn('id', $ids)->get(['id', 'project_id', 'client_id']) as $r) {
                    $out[$type.':'.$r->id] = [
                        is_string($r->project_id) ? $r->project_id : null,
                        is_string($r->client_id) ? $r->client_id : null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    private function clientsOf(string $workspaceId, array $projectIds): array
    {
        return array_values(array_map('strval', DB::table('projects')->where('workspace_id', $workspaceId)
            ->whereIn('id', $projectIds)->distinct()->pluck('client_id')->all()));
    }
}
