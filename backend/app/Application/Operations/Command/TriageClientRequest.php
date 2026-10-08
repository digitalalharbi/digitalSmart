<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\Exception\InvalidRequestTransition;
use App\Domain\Operations\OperationsBoard;

/**
 * Move a client request along: accept it, ask the client something, decline it, deliver it.
 *
 * Needs `client_requests.triage`, which a project manager holds and a media buyer does not. Agreeing to work for
 * a client and committing to a date is the conversation a project manager owns, and a queue where anybody could
 * accept on the agency's behalf is one where the agency has promised things nobody tracked.
 *
 * Two states carry a required reason, and both reasons are **shown to the client**: a decline, because a request
 * that vanished with nobody told why is the worst thing an intake queue can do, and a request for information,
 * because "we need something from you" is a message nobody can answer.
 *
 * And whoever raised it is **told**. A request that was accepted, declined or delivered with nobody told is a
 * client checking an inbox that never says anything, which is the same failure as the reason being required and
 * then never read. `NeedsInformation` gets its own kind, because it is the one move that puts the work back on
 * the client and the notification centre's one useful figure is "waiting on you".
 */
final readonly class TriageClientRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Announce $announce,
        private Clock $clock,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(
        string $requestId,
        ClientRequestState $state,
        ?string $reason = null,
    ): ClientRequestView {
        $this->guard->ensure(Permission::ClientRequestsTriage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            // Triage reads the internal side: whoever is deciding needs the notes.
            false,
        ) ?? throw ResourceNotFound::of('Client request');

        $current->state->assertCanMoveTo($state);

        if ($state->needsReason() && ($reason === null || trim($reason) === '')) {
            throw InvalidRequestTransition::reasonRequired($current->state, $state);
        }

        $moved = $this->requests->move(
            $workspaceId,
            $requestId,
            $state,
            $reason === null ? null : trim($reason),
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestMoved,
            auditableType: 'client_request',
            auditableId: $requestId,
            summary: sprintf('Moved "%s" to %s', $moved->title, $state->label()),
            changes: [
                'from' => $current->state->value,
                'to' => $state->value,
                'reason' => $reason === null ? null : trim($reason),
                /*
                 * How long the client had already waited when this happened. Recorded on the entry because it is
                 * the figure somebody asks about afterwards, and reconstructing it later means trusting that
                 * nothing else moved the timestamps.
                 */
                'client_had_waited_days' => $current->waitingForAnswerDays($this->clock->now()),
            ],
        ));

        $this->tell($current->submittedById, $moved, $state, $reason);
        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::ClientRequests);

        return $moved;
    }

    /**
     * Tell whoever raised it, if that was somebody other than the person moving it.
     *
     * The draft names **one** recipient and carries no project or client scope, which is deliberate and is the
     * only place in this product that does it: the audience is the person who wrote the request, so the question
     * the scope exists to answer — could they have read this? — is already answered by their having raised it. A
     * client viewer is on their own project and would usually pass the scope anyway; one who is not would be
     * silently dropped, and the one message a client is owed is the answer to their own question.
     *
     * The body carries the reason only where the state shows it to the client. A notification is a disclosure,
     * and a declined reason that the request itself withholds must not arrive in a bell.
     */
    private function tell(
        ?string $submittedById,
        ClientRequestView $moved,
        ClientRequestState $state,
        ?string $reason,
    ): void {
        if ($submittedById === null || $submittedById === $this->actor->userId()->value) {
            return;
        }

        $kind = $state === ClientRequestState::NeedsInformation
            ? NotificationKind::ClientRequestNeedsInformation
            : NotificationKind::ClientRequestMoved;

        ($this->announce)(new NotificationDraft(
            kind: $kind,
            title: $moved->title,
            subjectId: $moved->id,
            /* Both reasons this can carry are already shown to the client on the request itself. */
            body: $state->needsReason() && $reason !== null
                ? sprintf('%s · %s', $state->label(), trim($reason))
                : $state->label(),
            onlyUserId: $submittedById,
        ));
    }
}
