<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\CannotBeAssigned;

/**
 * Put a creative request in somebody's name, or in nobody's.
 *
 * `creative_requests.owner_id` could be set when a request was opened and **never afterwards**: the column was
 * written once by the composer and read by the board, and there was no way to hand a brief to anybody. A
 * creative hub where work cannot change hands is a list, not a hub.
 *
 * The owner has to be somebody who can open the request, through the same `OwnerDirectory` the task board and
 * the intake queue use. A brief carries a client's campaign and often their unreleased plans; an owner whose
 * projects do not reach that client would be a name on work they can never see.
 *
 * Its own use case and its own audit action rather than part of a move, because "who is making this" and "where
 * has it got to" are different questions whose trails are read by different people.
 */
final readonly class AssignCreativeOwner
{
    public function __construct(
        private CreativeRepository $requests,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $requestId, ?string $ownerId): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Creative request');

        if ($ownerId !== null) {
            $eligible = $this->owners->peopleWhoCanSee(
                $workspaceId,
                $current->clientId,
                Permission::CreativeView,
            );

            $isEligible = array_any(
                $eligible,
                static fn (OwnerCandidate $candidate): bool => $candidate->id === $ownerId,
            );

            if (! $isEligible) {
                throw CannotBeAssigned::toThatPerson($current->clientName);
            }
        }

        $assigned = $this->requests->assignOwner($workspaceId, $requestId, $ownerId);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CreativeOwnerChanged,
            auditableType: 'creative_request',
            auditableId: $requestId,
            summary: $ownerId === null
                ? sprintf('Took "%s" out of somebody\'s name', $assigned->title)
                : sprintf('Gave "%s" to %s', $assigned->title, $assigned->ownerName ?? 'somebody'),
            changes: [
                /* Both sides, so "who was making this in March" is answerable from the record. */
                'from' => $current->ownerId,
                'to' => $ownerId,
            ],
        ));

        return $assigned;
    }
}
