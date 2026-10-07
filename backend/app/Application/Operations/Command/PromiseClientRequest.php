<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use DateTimeImmutable;

/**
 * Commit to a date, or withdraw the commitment.
 *
 * Its own use case and its own audit action, because **a promised date is the thing a client quotes back**. "You
 * said the fifteenth" has to be answerable from the record rather than from somebody's memory of a call, and a
 * generic "updated" entry buries the one field anybody argues about.
 *
 * The promise always carries the person making it — enforced here and by a CHECK. A date that appeared with
 * nobody behind it is one the client will quote and nobody will own.
 */
final readonly class PromiseClientRequest
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $requestId, ?DateTimeImmutable $promisedAt): ClientRequestView
    {
        $this->guard->ensure(Permission::ClientRequestsTriage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            false,
        ) ?? throw ResourceNotFound::of('Client request');

        $promised = $this->requests->promise(
            $workspaceId,
            $requestId,
            $promisedAt,
            // Null date, null person. The CHECK requires them together, and so does the meaning.
            $promisedAt === null ? null : $this->actor->userId()->value,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestPromised,
            auditableType: 'client_request',
            auditableId: $requestId,
            summary: $promisedAt === null
                ? sprintf('Withdrew the promised date on "%s"', $promised->title)
                : sprintf('Promised "%s" by %s', $promised->title, $promisedAt->format('Y-m-d')),
            changes: [
                /*
                 * Both sides. Moving a promised date is the event a client notices, and an entry recording only
                 * the new one cannot answer "it was the twelfth last week".
                 */
                'from' => $current->promisedAt?->format('Y-m-d'),
                'to' => $promisedAt?->format('Y-m-d'),
                'wanted_by' => $current->wantedBy?->format('Y-m-d'),
            ],
        ));

        return $promised;
    }
}
