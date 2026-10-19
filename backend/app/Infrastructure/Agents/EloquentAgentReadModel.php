<?php

declare(strict_types=1);

namespace App\Infrastructure\Agents;

use App\Application\Agents\Contract\AgentReadModel;
use App\Application\Shared\Contract\Clock;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAgentReadModel implements AgentReadModel
{
    public function __construct(private Clock $clock) {}

    public function recentRuns(string $workspaceId, int $limit): array
    {
        return array_values(array_map(static fn (object $r): array => [
            'agent' => (string) $r->agent,
            'status' => (string) $r->status,
            'looked_at' => (int) $r->looked_at,
            'proposed' => (int) $r->proposed,
            'summary' => json_decode((string) $r->summary, true) ?: [],
            'started_at' => (string) $r->started_at,
            'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at,
        ], DB::table('agent_runs')->where('workspace_id', $workspaceId)->orderByDesc('started_at')->limit($limit)->get()->all()));
    }

    public function openProposals(string $workspaceId, ?array $visibleProjectIds): array
    {
        $query = DB::table('approval_requests')->where('workspace_id', $workspaceId)
            ->where('state', 'pending')->whereNotNull('agent');

        if ($visibleProjectIds !== null) {
            $visibleProjectIds === [] ? $query->whereRaw('false') : $query->whereIn('project_id', $visibleProjectIds);
        }

        $out = [];

        foreach ($query->selectRaw('agent, count(*) as n')->groupBy('agent')->get() as $row) {
            $out[(string) $row->agent] = (int) $row->n;
        }

        return $out;
    }

    public function toolCounts(string $workspaceId): array
    {
        $out = [];

        foreach (DB::table('agent_tool_calls')->where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $this->clock->now()->modify('-1 day'))
            ->selectRaw("tool, count(*) as calls, count(*) filter (where outcome = 'refused') as refused")
            ->groupBy('tool')->get() as $row) {
            $out[(string) $row->tool] = ['calls' => (int) $row->calls, 'refused' => (int) $row->refused];
        }

        return $out;
    }

    public function lastSync(string $workspaceId): ?string
    {
        $value = DB::table('sync_runs')->where('workspace_id', $workspaceId)->max('started_at');

        return $value === null ? null : (string) $value;
    }

    public function connectedProviders(string $workspaceId): array
    {
        return array_values(array_map('strval', DB::table('provider_connections')->where('workspace_id', $workspaceId)->distinct()->pluck('provider')->all()));
    }
}
