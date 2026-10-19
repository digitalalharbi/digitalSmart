<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

interface AgentReadModel
{
    /** @return list<array{agent: string, status: string, looked_at: int, proposed: int, summary: array<string, mixed>, started_at: string, finished_at: string|null}> */
    public function recentRuns(string $workspaceId, int $limit): array;

    /** @return array<string, array{calls: int, refused: int}> tool => counts over the last day */
    public function toolCounts(string $workspaceId): array;

    public function lastSync(string $workspaceId): ?string;

    /** @return list<string> provider slugs with a connection in this workspace */
    public function connectedProviders(string $workspaceId): array;

    /**
     * Pending proposals each agent prepared, in the reader's projects (null: every project).
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int> agent => pending proposals
     */
    public function openProposals(string $workspaceId, ?array $visibleProjectIds): array;
}
