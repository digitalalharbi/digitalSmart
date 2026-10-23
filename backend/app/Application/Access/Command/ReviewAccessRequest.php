<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Record what became of a request: somebody got in touch, or it was approved, or it was declined.
 *
 * Approving does not add anybody. It says the decision was yes; the member is then added through the ordinary flow,
 * where the one-time password is read out — the only way to hand over an account without a sender.
 */
final readonly class ReviewAccessRequest
{
    public function __construct(
        private AccessRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $id, AccessRequestStatus $status, ?string $note): AccessRequestView
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId();
        $workspaceId = $workspace->value;
        $request = $this->requests->find($workspaceId, $id) ?? throw ResourceNotFound::of('Access request');

        if (! $request->status->canMoveTo($status)) {
            throw AccessRequestRefused::cannotMove($request->status, $status);
        }

        $this->requests->decide(
            $workspaceId,
            $id,
            $status,
            $this->actor->userId()->value,
            $note === null || trim($note) === '' ? null : trim($note),
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $workspace,
            actorId: $this->actor->userId(),
            action: AuditAction::AccessRequestReviewed,
            auditableType: 'access_request',
            auditableId: $id,
            summary: sprintf('Marked a request for an account as %s', $status->value),
            /* The decision and nothing about the person: the trail is read by more people than the request is. */
            changes: ['from' => $request->status->value, 'to' => $status->value],
        ));

        return $this->requests->find($workspaceId, $id) ?? throw ResourceNotFound::of('Access request');
    }
}
