<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;

/**
 * Put somebody's name on a client's request.
 *
 * Needs `client_requests.triage`, like accepting one: saying who will answer a client is part of the same
 * conversation as agreeing to answer them at all.
 *
 * The owner is **the person the client's question belongs to**, which is not the same as whoever happens to do
 * the work — that lives on the tasks the request spawns, each with its own assignee. Conflating them would mean a
 * request with three tasks had three owners and nobody to ask.
 *
 * Unassigning is a first-class outcome. A queue that can only ever add an owner accumulates requests owned by
 * whoever opened them first, and then "who is handling this" is a name rather than an answer.
 */
final readonly class AssignRequestOwner
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Announce $announce,
    ) {}

    public function __invoke(string $requestId, ?string $ownerId): ClientRequestView
    {
        $this->guard->ensure(Permission::ClientRequestsTriage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            false,
        ) ?? throw ResourceNotFound::of('Client request');

        $assigned = $this->requests->assignOwner($workspaceId, $requestId, $ownerId);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestOwnerChanged,
            auditableType: 'client_request',
            auditableId: $requestId,
            summary: $ownerId === null
                ? sprintf('Removed the owner from "%s"', $assigned->title)
                : sprintf('Gave "%s" to %s', $assigned->title, $assigned->ownerName ?? 'somebody'),
            changes: [
                // Both sides, so "who was handling this in March" is answerable from the record.
                'from' => $current->ownerId,
                'to' => $ownerId,
                'client_id' => $assigned->clientId,
            ],
        ));

        if ($ownerId !== null) {
            /*
             * The new owner, and never the actor — `Announce` drops them. Taking a request yourself needs no
             * notification; being handed a client's question does.
             */
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::ClientRequestSubmitted,
                title: $assigned->title,
                subjectId: $assigned->id,
                body: sprintf('%s · %s', $assigned->clientName, $assigned->kind->label()),
                onlyUserId: $ownerId,
                aboutProjectId: $assigned->projectId,
                aboutClientId: $assigned->clientId,
            ));
        }

        return $assigned;
    }
}
