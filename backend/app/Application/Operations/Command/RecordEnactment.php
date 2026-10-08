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
use App\Domain\Operations\OperationsBoard;

/**
 * Record that somebody made the approved change — or deliberately did not.
 *
 * This use case exists because **this product does not make the change itself**. There is no write adapter for
 * any of these kinds yet, so an approval authorises a person, and this is that person saying what they did.
 *
 * It is deliberately a **claim with a name and a time on it**, not a measurement. The product has not verified
 * anything on the platform, and the response says so. Pretending otherwise would be worse than the gap: a
 * reader who believes the product checked will stop checking.
 *
 * Abandoning requires a note, because not carrying out an approved change is a second decision — and the one a
 * client is most likely to ask about.
 */
final readonly class RecordEnactment
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
        EnactmentState $enactment,
        ?string $note = null,
    ): ApprovalView {
        /*
         * The requester's permission, not the decider's. The person who asked for the change is usually the one
         * who goes and makes it, and requiring a decider here would leave approved work waiting on somebody
         * with no reason to be involved any more.
         */
        $this->guard->ensure(Permission::ApprovalsRequest);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Approval request');

        if ($current->state !== ApprovalState::Approved) {
            throw InvalidApprovalTransition::notApproved($current->state);
        }

        if ($current->enactment->isSettled()) {
            // Already done or already abandoned. Recording it twice would overwrite who did it and when.
            throw InvalidApprovalTransition::enactmentSettled($current->state, $current->enactment);
        }

        if ($enactment === EnactmentState::Abandoned && ($note === null || trim($note) === '')) {
            throw InvalidApprovalTransition::reasonRequired($current->state, ApprovalState::Declined);
        }

        $recorded = $this->approvals->recordEnactment(
            $workspaceId,
            $requestId,
            $enactment,
            $note === null ? null : trim($note),
            $this->actor->userId()->value,
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalEnacted,
            auditableType: 'approval_request',
            auditableId: $requestId,
            summary: sprintf('%s: "%s"', $enactment->label(), $recorded->title),
            changes: [
                'enactment' => $enactment->value,
                'note' => $note,
                /*
                 * Stated on the entry: this is somebody's word, not a reading from the platform. An audit of
                 * live spend changes has to be able to tell the two apart.
                 */
                'verified_by_product' => false,
                'days_after_decision' => $recorded->decidedAt === null
                    ? null
                    : (int) $recorded->decidedAt->setTime(0, 0)
                        ->diff($this->clock->now()->setTime(0, 0))->days,
            ],
        ));

        /*
         * Back to whoever decided it, which closes the loop this module exists to keep open: the person who
         * approved a change to live spend is the person who should learn whether it actually happened. Nobody
         * else is told — a change being made is not news to the people who were not asked about it.
         */
        if ($current->decidedById !== null) {
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::ApprovalEnacted,
                title: $recorded->title,
                subjectId: $requestId,
                body: $enactment->label().($note === null ? '.' : ' — '.trim($note)),
                onlyUserId: $current->decidedById,
                aboutProjectId: $recorded->projectId,
                aboutClientId: $recorded->clientId,
            ));
        }

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::Approvals);

        return $recorded;
    }
}
