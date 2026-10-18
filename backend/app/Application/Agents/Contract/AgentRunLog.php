<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

interface AgentRunLog
{
    public function start(string $workspaceId, string $agent): string;

    /** @param array<string, mixed> $summary */
    public function progress(string $runId, string $status, int $lookedAt, int $proposed, array $summary): void;

    public function fail(string $runId, string $message): void;

    /** Workspaces that have anything to look at. */
    /** @return list<string> */
    public function workspaces(?string $only): array;

    /**
     * Pending agent proposals past their expiry, withdrawn as expired. Returns how many.
     */
    public function expireProposals(): int;
}
