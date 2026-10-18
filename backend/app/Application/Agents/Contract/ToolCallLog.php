<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

use DateTimeImmutable;

/** The append-only record of every tool call, whoever made it. */
interface ToolCallLog
{
    /** @param array<string, mixed> $input */
    public function record(string $workspaceId, ?string $userId, string $tool, string $channel, array $input, string $outcome, ?string $errorCode, int $durationMs): void;

    /** Calls by this user to this tool since — for the rate limit. */
    public function countSince(string $workspaceId, ?string $userId, string $tool, DateTimeImmutable $since): int;
}
