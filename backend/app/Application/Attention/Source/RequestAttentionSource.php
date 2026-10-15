<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\IntakeLoad;
use DateTimeImmutable;

/**
 * What clients are waiting for.
 *
 * A missed promise outranks a quiet conversation. Both matter; one of them is a date the agency gave a
 * client and did not meet.
 *
 * **Only what the agency is holding up.** A request waiting on the client is not this agency's to act on,
 * and listing it would pad the queue with work nobody here can clear — which is how a to-do list stops
 * being a to-do list.
 */
final readonly class RequestAttentionSource implements AttentionSource
{
    private const int CAP = 100;

    public function __construct(
        private ClientRequestRepository $requests,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Requests;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $now = $this->clock->now();
        $items = [];

        /* The domain says which states the agency is holding up; only those are read. */
        $states = array_values(array_filter(
            ClientRequestState::cases(),
            static fn (ClientRequestState $state): bool => $state->isOnTheAgency(),
        ));

        $requests = $this->requests->inStates(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $states,
            self::CAP,
        );

        foreach ($requests as $request) {
            $item = $this->toItem($request, $now);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function toItem(ClientRequestView $request, DateTimeImmutable $now): ?AttentionItem
    {
        if ($request->state->isOnTheClient()) {
            return null;
        }

        if ($request->isOverdue($now)) {
            return $this->item(
                $request,
                AttentionSeverity::Critical,
                sprintf('Past the date promised to the client: %s', $request->title),
            );
        }

        if ($request->isUnanswered() && $request->waitingForAnswerDays($now) >= IntakeLoad::PATIENCE_DAYS) {
            return $this->item(
                $request,
                AttentionSeverity::NeedsAttention,
                sprintf('Still unanswered: %s', $request->title),
            );
        }

        return null;
    }

    private function item(
        ClientRequestView $request,
        AttentionSeverity $severity,
        string $message,
    ): AttentionItem {
        return new AttentionItem(
            id: 'request:'.$request->id,
            stream: AttentionStream::Requests,
            severity: $severity,
            message: $message,
            entityKind: $request->kind->label(),
            entityLabel: $request->clientName,
            owner: $request->ownerName,
            since: $request->createdAt,
            clientId: $request->clientId,
            clientName: $request->clientName,
            projectId: $request->projectId,
            projectName: $request->projectName,
        );
    }
}
