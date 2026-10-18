<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Agents\Contract\ToolCallLog;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentToolCallLog implements ToolCallLog
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function record(string $workspaceId, ?string $userId, string $tool, string $channel, array $input, string $outcome, ?string $errorCode, int $durationMs): void
    {
        DB::table('agent_tool_calls')->insert([
            'id' => $this->ids->generate(),
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'tool' => $tool,
            'channel' => $channel,
            'input' => json_encode($input, JSON_THROW_ON_ERROR),
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'duration_ms' => $durationMs,
            'created_at' => $this->clock->now(),
        ]);
    }

    public function countSince(string $workspaceId, ?string $userId, string $tool, DateTimeImmutable $since): int
    {
        return DB::table('agent_tool_calls')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('tool', $tool)
            ->where('created_at', '>=', $since)
            ->count();
    }
}
