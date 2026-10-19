<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

use App\Application\Agents\DTO\AgentFinding;

/**
 * Where specialist agents leave what they saw.
 *
 * `observe` is the whole of an agent's output for one run: findings it saw again are refreshed, new ones opened, and
 * any it no longer sees are resolved. A finding is information, never an action — it changes no campaign, budget or
 * client data.
 */
interface FindingLog
{
    /**
     * @param  string  $agent  creative, leads, reports or operations
     * @param  list<AgentFinding>  $findings
     * @return array{opened: int, kept: int, resolved: int}
     */
    public function observe(string $workspaceId, string $agent, array $findings): array;

    /**
     * Open findings in projects the reader may see (null: every project). A finding with no project is
     * workspace-wide and shown only to a reader who sees every project.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array{id: string, agent: string, code: string, subject_kind: string, subject_id: string, subject_label: string, client_id: string|null, client_name: string|null, project_id: string|null, project_name: string|null, severity: string, message: string, params: array<string, int|string|float>, evidence: array<string, mixed>, task_id: string|null, first_seen_at: string}>
     */
    public function open(string $workspaceId, ?array $visibleProjectIds, int $limit = 60): array;

    /** Whether this subject already has an open finding with this code — so an agent does not open its task twice. */
    public function isOpen(string $workspaceId, string $agent, string $code, string $subjectId): bool;
}
