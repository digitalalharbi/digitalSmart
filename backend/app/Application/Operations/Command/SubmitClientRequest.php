<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestInput;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Operations\OperationsBoard;

/**
 * Raise something a client wants.
 *
 * Needs `client_requests.submit`, which a **client viewer holds** — the only permission in this product that
 * lets a client write anything. That is the point of the module: an intake queue only agency staff can write to
 * is just another task list.
 *
 * Whether the request is recorded as coming **from the client** is decided here, from whether the actor can see
 * the agency's own queue. It is stored on the row rather than derived later, because a member's role can change
 * and which side of a conversation a message came from cannot.
 */
final readonly class SubmitClientRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Announce $announce,
        private BoardSignaller $boards,
    ) {}

    public function __invoke(ClientRequestInput $input): ClientRequestView
    {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($input->projectId !== null && $visible !== null && ! in_array($input->projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        /*
         * A client raising a request is somebody without the agency's own view of the queue. Asked of the guard
         * rather than of a role name, so a workspace that invents its own client-side role gets the same answer.
         */
        $asClient = ! $this->guard->allows(Permission::ClientRequestsView);

        $request = $this->requests->submit(
            $workspaceId,
            $input,
            $this->actor->userId()->value,
            $asClient,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestSubmitted,
            auditableType: 'client_request',
            auditableId: $request->id,
            summary: sprintf('Raised "%s" for %s', $request->title, $request->clientName),
            changes: [
                'kind' => $request->kind->value,
                /*
                 * Recorded as the client's word. Nothing in this product turns it into a priority — see
                 * `StatedUrgency` — and an audit that conflated them would be evidence for the wrong claim.
                 */
                'stated_urgency' => $request->urgency->value,
                'wanted_by' => $request->wantedBy?->format('Y-m-d'),
                'from_client' => $asClient,
                'client_id' => $request->clientId,
            ],
        ));

        if ($asClient) {
            /*
             * Only when a client raised it. An agency member filing a request on a client's behalf is already in
             * the room, and telling the rest of the team about it is the kind of notification that fills a bell
             * with things nobody has to do.
             */
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::ClientRequestSubmitted,
                title: $request->title,
                subjectId: $request->id,
                body: sprintf('%s · %s', $request->clientName, $request->kind->label()),
                aboutProjectId: $request->projectId,
                aboutClientId: $request->clientId,
            ));
        }

        /* Whoever has the board open learns it moved. A nudge with no rows in it; see `BoardSignaller`. */
        $this->boards->changed(OperationsBoard::ClientRequests);

        return $request;
    }
}
