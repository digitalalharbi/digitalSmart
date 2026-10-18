<?php

declare(strict_types=1);

namespace App\Infrastructure\Agents;

use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Operations\ApprovalState;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAgentRunLog implements AgentRunLog
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function start(string $workspaceId, string $agent): string
    {
        $id = $this->ids->generate();

        DB::table('agent_runs')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'agent' => $agent,
            'status' => 'analysing',
            'started_at' => $this->clock->now(),
        ]);

        return $id;
    }

    public function progress(string $runId, string $status, int $lookedAt, int $proposed, array $summary): void
    {
        $done = in_array($status, ['completed', 'waiting_approval'], true);

        DB::table('agent_runs')->where('id', $runId)->update([
            'status' => $status,
            'looked_at' => $lookedAt,
            'proposed' => $proposed,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
            'finished_at' => $done ? $this->clock->now() : null,
        ]);
    }

    public function fail(string $runId, string $message): void
    {
        DB::table('agent_runs')->where('id', $runId)->update(['status' => 'failed', 'failure' => $message, 'finished_at' => $this->clock->now()]);
    }

    public function workspaces(?string $only): array
    {
        $query = DB::table('workspaces')->select('id');

        if ($only !== null) {
            $query->where('id', $only);
        }

        return array_values(array_map(static fn (object $row): string => (string) $row->id, $query->get()->all()));
    }

    public function expireProposals(): int
    {
        return DB::table('approval_requests')
            ->where('origin', 'agent')
            ->where('state', ApprovalState::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $this->clock->now())
            ->update([
                'state' => ApprovalState::Withdrawn->value,
                'withdrawn_reason' => 'Expired: the figures this rested on have moved on. A new one will be proposed if it still holds.',
                'updated_at' => $this->clock->now(),
            ]);
    }
}
