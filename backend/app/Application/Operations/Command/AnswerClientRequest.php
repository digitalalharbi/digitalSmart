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
use App\Domain\Operations\MessageAudience;

/**
 * Say something on a client request.
 *
 * Two rules, and the first is the one that protects a relationship.
 *
 * **A client can only write to the client.** Somebody without the agency's own view of the queue has their
 * audience forced to `Client`, whatever they sent — their own message must reach the person they are talking to,
 * and a client filing a note as internal would hide their own words from themselves. A CHECK enforces the same
 * thing from below.
 *
 * **An agency member must choose, every time.** There is no default audience. An internal note about a client
 * reaching that client ends a relationship, and a default is exactly how it happens.
 *
 * A **client-visible** message from the agency also stamps the first-answer time, which is what makes "how long
 * did the client wait" a measurement. An internal note does not: writing to each other is not answering.
 */
final readonly class AnswerClientRequest
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
        MessageAudience $audience,
        string $body,
    ): ClientRequestView {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        $workspaceId = $this->actor->workspaceId()->value;
        $isAgency = $this->guard->allows(Permission::ClientRequestsView);

        $current = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            ! $isAgency,
        ) ?? throw ResourceNotFound::of('Client request');

        $audienceUsed = $isAgency ? $audience : MessageAudience::Client;

        /*
         * Only a client-visible message from the agency answers the client. Computed here and recorded on the
         * entry, because the repository already applies exactly this rule when it stamps `first_answered_at` —
         * and an audit entry claiming an internal note was the first answer would contradict the column it
         * describes.
         */
        $isFirstAnswer = $isAgency && $audienceUsed->isVisibleToClient() && $current->isUnanswered();

        $answered = $this->requests->message(
            $workspaceId,
            $requestId,
            // Forced for a client. See the note above, and the CHECK that says the same thing.
            $audienceUsed,
            trim($body),
            $this->actor->userId()->value,
            ! $isAgency,
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestAnswered,
            auditableType: 'client_request',
            auditableId: $requestId,
            summary: sprintf('Replied on "%s"', $answered->title),
            changes: [
                /*
                 * The audience, on the entry. It is the field an argument about a leaked note turns on, and the
                 * message itself is deliberately not copied here — the audit log is not a second place a client's
                 * internal notes live.
                 */
                'audience' => $audienceUsed->value,
                'from_client' => ! $isAgency,
                'was_first_answer' => $isFirstAnswer,
            ],
        ));

        return $answered;
    }
}
