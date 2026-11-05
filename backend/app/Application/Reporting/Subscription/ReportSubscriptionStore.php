<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Domain\Reporting\EmailCadence;
use App\Domain\Reporting\EmailDeliveryState;
use DateTimeImmutable;

/**
 * Who receives which report by email, and what happened to each period's message.
 */
interface ReportSubscriptionStore
{
    /** Whether this address already receives this report, in any status. */
    public function exists(string $workspaceId, string $reportId, string $email): bool;

    public function create(
        string $workspaceId,
        string $reportId,
        string $email,
        ?string $name,
        string $locale,
        EmailCadence $cadence,
        string $consentNote,
        string $unsubscribeTokenHash,
        string $createdBy,
        DateTimeImmutable $at,
    ): ReportSubscription;

    public function find(string $workspaceId, string $id): ?ReportSubscription;

    /** @return list<ReportSubscription> */
    public function forReport(string $workspaceId, string $reportId): array;

    public function setStatus(string $id, string $status, ?string $haltedReason = null): void;

    public function remove(string $workspaceId, string $id): void;

    /**
     * Replace the unsubscribe credential. Each email carries a fresh one, so the row never holds a token anybody
     * could read back and an old email's link stops working when a newer one is sent.
     */
    public function rotateUnsubscribeToken(string $id, string $tokenHash): void;

    /**
     * Every active subscription, in every workspace. The caller decides what is due.
     *
     * @return list<ReportSubscription>
     */
    public function active(int $limit): array;

    /**
     * Claim one period for one subscription.
     *
     * Returns the delivery's id when this run may send — a new row, or an earlier one whose state may be retried and
     * that has attempts left — and null when the period is already settled. The unique key is the guard: two runs
     * claiming at once cannot both win.
     */
    public function claim(string $workspaceId, string $subscriptionId, string $periodKey, int $maxAttempts, DateTimeImmutable $at): ?string;

    public function settle(string $deliveryId, EmailDeliveryState $state, ?string $detail, ?string $providerMessageId, ?string $shareId): void;

    /**
     * @return list<array{id: string, period_key: string, state: EmailDeliveryState, detail: ?string, attempts: int, attempted_at: ?DateTimeImmutable}>
     */
    public function deliveries(string $workspaceId, string $subscriptionId, int $limit): array;

    /** Mark the subscription holding this unsubscribe credential as unsubscribed, and return it; null when none was. */
    public function unsubscribeByTokenHash(string $tokenHash): ?ReportSubscription;
}
