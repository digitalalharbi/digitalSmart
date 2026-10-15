<?php

declare(strict_types=1);

namespace App\Application\Project\Contract;

use DateTimeImmutable;

/**
 * The raw facts a readiness report is judged from. Facts only — what they mean is decided in
 * `GetProjectReadiness`, so the sentence and the data it rests on are never in the same place twice.
 */
interface ReadinessFacts
{
    /** @return array{id: string, name: string, client_id: string, status: string}|null */
    public function project(string $workspaceId, string $projectId): ?array;

    /**
     * Approved budgets for the project, or the client's project-less ones.
     *
     * @return array{any: bool, covers_today: bool}
     */
    public function approvedBudget(string $workspaceId, string $projectId, string $clientId, string $today): array;

    /** @return array{bound: int, usable: int} */
    public function boundAccounts(string $workspaceId, string $projectId): array;

    /** @return array{briefed: bool, approved: bool} */
    public function creative(string $workspaceId, string $projectId, string $clientId): array;

    /**
     * What is unknown about the project's bound accounts, which is what stops their figures being trusted.
     *
     * `timezones` counts distinct *known* zones: two accounts in two zones put their day boundaries in
     * different places, so a daily trend across them is a sum of days that do not line up. `unplaced` is the
     * campaigns on those accounts that no project has been chosen for (several projects share the account,
     * and nobody has said which owns it).
     *
     * @return array{accounts: int, missing_currency: int, missing_timezone: int, timezones: int, unplaced: int}
     */
    public function dataQuality(string $workspaceId, string $projectId): array;

    /** Pending approvals older than `$olderThan`. */
    public function stuckApprovals(string $workspaceId, string $projectId, DateTimeImmutable $olderThan): int;
}
