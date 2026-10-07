<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
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
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\Exception\InvalidRequestTransition;

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
 */
final readonly class TriageClientRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
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

        return $moved;
    }
}
