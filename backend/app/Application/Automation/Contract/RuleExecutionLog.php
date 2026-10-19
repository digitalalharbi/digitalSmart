<?php

declare(strict_types=1);

namespace App\Application\Automation\Contract;

use DateTimeImmutable;

/**
 * Every action a rule took, was refused, or found unnecessary — the Action History, and what the summary counts.
 */
interface RuleExecutionLog
{
    /**
     * @param  array{rule_id: string, campaign_id: string|null, client_id: string|null, project_id: string|null, provider: string|null, object_level: string, object_label: string, action: string, before: array<string, mixed>, after: array<string, mixed>, result: string, reason_code: string|null, approval_id: string|null}  $entry
     */
    public function record(string $workspaceId, array $entry): void;

    /** When this rule last acted on this object, if within the window. */
    public function lastActedAt(string $ruleId, string $campaignId, DateTimeImmutable $since): ?DateTimeImmutable;

    /**
     * Whether another rule moved this object the opposite way since `$since` — the second rule then stands down
     * rather than undo the first.
     *
     * @param  list<string>  $opposite
     */
    public function conflicting(string $ruleId, string $campaignId, array $opposite, DateTimeImmutable $since): ?string;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @param  array{rule_id?: string|null, result?: string|null, provider?: string|null, client_id?: string|null, project_id?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function history(string $workspaceId, ?array $visibleProjectIds, array $filters, DateTimeImmutable $from, int $limit): array;

    /**
     * Action counts by kind over the window and the one before it.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, array{now: int, before: int}>
     */
    public function counts(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Who changed which rule, what, and when — from the audit trail. `$ruleIds` null is every rule in the workspace.
     *
     * @param  list<string>|null  $ruleIds
     * @return list<array{at: string, who: string|null, rule_id: string, rule: string|null, kinds: list<string>, before: array<string, mixed>|null, after: array<string, mixed>|null}>
     */
    public function changeLog(string $workspaceId, ?array $ruleIds, DateTimeImmutable $from, int $limit): array;

    /** @return list<string> Rules that acted (completed or raised a proposal) since the date. */
    public function rulesActedSince(string $workspaceId, DateTimeImmutable $since): array;
}
