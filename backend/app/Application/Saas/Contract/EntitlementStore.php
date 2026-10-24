<?php

declare(strict_types=1);

namespace App\Application\Saas\Contract;

use App\Application\Saas\DTO\Entitlements;
use App\Application\Saas\DTO\OverrideRecord;
use App\Application\Saas\DTO\SubscriptionState;
use App\Domain\Saas\SubscriptionStatus;
use DateTimeImmutable;

interface EntitlementStore
{
    /** The workspace's plan with its overrides applied. A workspace with no subscription gets the configured default plan. */
    public function forWorkspace(string $workspaceId): Entitlements;

    public function planExists(string $code): bool;

    public function assignPlan(string $workspaceId, string $planCode, string $assignedBy): void;

    /**
     * Record an override. `$value` is `on`/`off` for a feature, a number or `unlimited` for a limit, or `inherit` to return the
     * key to the plan. Returns the value that was in force before.
     */
    public function override(string $workspaceId, string $kind, string $key, string $value, string $reason, string $changedBy): string;

    /** @return list<OverrideRecord> newest first */
    public function overrides(string $workspaceId, int $limit = 50): array;

    /** The workspace's subscription state; `active` for a workspace with no subscription row. */
    public function subscription(string $workspaceId): SubscriptionState;

    public function changeStatus(
        string $workspaceId,
        SubscriptionStatus $status,
        ?DateTimeImmutable $trialEndsAt,
        ?DateTimeImmutable $graceEndsAt,
        ?string $reason,
        string $changedBy,
    ): void;
}
