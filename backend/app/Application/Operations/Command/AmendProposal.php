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
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use App\Domain\Operations\OperationsBoard;

/**
 * Change a proposal that nobody has decided on yet.
 *
 * Only while pending. A settled request is never amended — that would produce a record claiming somebody
 * approved something they never read — so a changed proposal after a decision is a **new request** naming the
 * one it replaces, and both decisions stay on the trail.
 *
 * Amending moves the fingerprint, which is what makes the fingerprint check on a decision real rather than
 * theoretical: anyone who had the old proposal open is refused and sent back to read the new one.
 */
final readonly class AmendProposal
{
    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private BoardSignaller $boards,
    ) {}

    /** @param array<string, mixed> $proposal */
    public function __invoke(
        string $requestId,
        string $title,
        string $rationale,
        array $proposal,
    ): ApprovalView {
        $this->guard->ensure(Permission::ApprovalsRequest);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Approval request');

        if (! $current->state->isAmendable()) {
            throw InvalidApprovalTransition::alreadySettled($current->state, $current->state);
        }

        if (trim($rationale) === '') {
            throw InvalidApprovalRequest::rationaleRequired();
        }

        if ($proposal === []) {
            throw InvalidApprovalRequest::proposalRequired();
        }

        $amended = $this->approvals->amend($workspaceId, $requestId, trim($title), trim($rationale), $proposal);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalAmended,
            auditableType: 'approval_request',
            auditableId: $requestId,
            summary: sprintf('Amended "%s"', $amended->title),
            changes: [
                /*
                 * Both fingerprints. It is the evidence that the proposal changed, and the answer to "but I
                 * approved the one that said X" when somebody decided on the earlier version.
                 */
                'from_fingerprint' => $current->fingerprint,
                'to_fingerprint' => $amended->fingerprint,
            ],
        ));

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Approvals);

        return $amended;
    }
}
