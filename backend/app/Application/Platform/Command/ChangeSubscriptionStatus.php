<?php

declare(strict_types=1);

namespace App\Application\Platform\Command;

use App\Application\Platform\Exception\PlatformRefused;
use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Move a workspace to another subscription state. Always with a reason, always audited in the workspace's own trail, and never
 * touching its data: suspending stops writes and scheduled work, and resuming starts them again on everything that was there.
 */
final readonly class ChangeSubscriptionStatus
{
    public function __construct(
        private EntitlementStore $store,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        string $workspaceId,
        SubscriptionStatus $status,
        ?DateTimeImmutable $trialEndsAt,
        ?DateTimeImmutable $graceEndsAt,
        string $reason,
        string $operator,
    ): void {
        if (trim($reason) === '') {
            throw PlatformRefused::reasonRequired();
        }

        $before = $this->store->subscription($workspaceId)->status;
        $this->store->changeStatus(
            $workspaceId,
            $status,
            $status === SubscriptionStatus::Trial ? $trialEndsAt : null,
            $status === SubscriptionStatus::Grace ? $graceEndsAt : null,
            $reason,
            $operator,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($workspaceId),
            actorId: null,
            action: AuditAction::WorkspaceSubscriptionChanged,
            auditableType: 'workspace',
            auditableId: $workspaceId,
            summary: sprintf('%s moved the subscription from %s to %s.', $operator, $before->value, $status->value),
            changes: ['status' => ['from' => $before->value, 'to' => $status->value], 'operator' => $operator, 'reason' => $reason],
        ));
    }
}
