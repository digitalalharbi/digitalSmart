<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use App\Domain\Operations\Exception\ProposalChanged;
use App\Domain\Operations\OperationsBoard;
use App\Domain\Operations\ProposalFingerprint;

/**
 * Say yes or no.
 *
 * Three rules, and each of them is a thing an approval trail is for.
 *
 * **The decider names the proposal they read.** The caller sends the fingerprint their screen displayed, and a
 * mismatch is refused with what the proposal says now. Without it, an amendment landing between opening and
 * approving produces a record claiming somebody approved a change they never saw — a race that looks exactly
 * like a correct approval afterwards.
 *
 * **Some kinds need a second permission.** Changing what a client spends is signed off by whoever signs off the
 * figure. `ApprovalKind::decidedWith()` says which, and the guard checks it in addition to `approvals.decide`.
 *
 * **Approving does not carry the change out.** This product does not write to the platforms, so an approved
 * request moves to *awaiting a hand* and the queue counts it until somebody says they did it. Setting it
 * straight to carried-out would be this module's one unforgivable lie.
 */
final readonly class DecideApproval
{
    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
        private Announce $announce,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(
        string $requestId,
        ApprovalState $outcome,
        ?string $fingerprint,
        ?string $reason = null,
    ): ApprovalView {
        $this->guard->ensure(Permission::ApprovalsDecide);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Approval request');

        $second = $current->kind->decidedWith();

        if ($second !== null) {
            $this->guard->ensure($second);
        }

        $current->state->assertCanMoveTo($outcome);

        if ($outcome === ApprovalState::Withdrawn) {
            /*
             * Withdrawing is the requester's act, not the decider's, and it has its own use case. Allowing it
             * here would let a decider retire a request they did not want to answer without declining it —
             * which is the same outcome with none of the record.
             */
            throw InvalidApprovalTransition::alreadySettled($current->state, $outcome);
        }

        $recorded = ProposalFingerprint::fromStored($current->fingerprint);

        if (! $recorded->matches($fingerprint)) {
            throw new ProposalChanged($current->fingerprint, $fingerprint);
        }

        if ($outcome->needsReason() && ($reason === null || trim($reason) === '')) {
            throw InvalidApprovalTransition::reasonRequired($current->state, $outcome);
        }

        $decided = $this->approvals->decide(
            $workspaceId,
            $requestId,
            $outcome,
            $reason,
            $this->actor->userId()->value,
            $current->fingerprint,
            $this->clock->now(),
            $outcome === ApprovalState::Approved
                ? EnactmentState::AwaitingHand
                : EnactmentState::NotRequired,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalDecided,
            auditableType: 'approval_request',
            auditableId: $requestId,
            summary: sprintf('%s "%s"', $outcome->label(), $decided->title),
            changes: [
                'outcome' => $outcome->value,
                'reason' => $reason,
                /*
                 * The fingerprint is the point of this entry. It is what makes "I approved the version that
                 * said 500, not 800" a settled question rather than an argument.
                 */
                'fingerprint' => $current->fingerprint,
                'kind' => $decided->kind->value,
                'touches_live_spend' => $decided->kind->touchesLiveSpend(),
                /* Recorded as false while no write adapter exists, so the trail is explicit about it. */
                'enacted_by_product' => $decided->kind->isEnactedByThisProduct(),
            ],
        ));

        /*
         * The person who asked, and only them. The body carries the decline reason, because a notification saying
         * "declined" with the reason a click away is a notification that generates a second conversation.
         */
        if ($current->requestedById !== null) {
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::ApprovalDecided,
                title: $decided->title,
                subjectId: $requestId,
                body: $outcome->label().($reason === null ? '.' : ' — '.$reason),
                onlyUserId: $current->requestedById,
                aboutProjectId: $decided->projectId,
                aboutClientId: $decided->clientId,
            ));
        }

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Approvals);

        return $decided;
    }
}
