<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use App\Domain\Operations\OperationsBoard;

/**
 * Take a request back.
 *
 * Needs `approvals.request` rather than `approvals.decide`: withdrawing is the asker's act. It carries a reason
 * for the same purpose a cancelled task does — a request that simply vanished from the queue is indistinguishable
 * from one somebody quietly dropped to avoid a no.
 */
final readonly class WithdrawApproval
{
    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(string $requestId, ?string $reason): ApprovalView
    {
        $this->guard->ensure(Permission::ApprovalsRequest);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Approval request');

        $current->state->assertCanMoveTo(ApprovalState::Withdrawn);

        if ($reason === null || trim($reason) === '') {
            throw InvalidApprovalTransition::reasonRequired($current->state, ApprovalState::Withdrawn);
        }

        $withdrawn = $this->approvals->withdraw($workspaceId, $requestId, trim($reason));

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalWithdrawn,
            auditableType: 'approval_request',
            auditableId: $requestId,
            summary: sprintf('Withdrew "%s"', $withdrawn->title),
            changes: ['reason' => trim($reason)],
        ));

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Approvals);

        return $withdrawn;
    }
}
