<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeRequestInput;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\CannotBeAssigned;

/** Brief a piece of creative work. */
final readonly class OpenCreativeRequest
{
    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private OwnerDirectory $owners,
    ) {}

    public function __invoke(CreativeRequestInput $input): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeManage);

        if ($input->ownerId !== null) {
            /*
             * The same check `AssignCreativeOwner` makes, because briefing work already in somebody's name has
             * the same consequence as handing it to them afterwards: a name on work they can never open. A brief
             * carries a client's campaign and often their unreleased plans.
             */
            $eligible = $this->owners->peopleWhoCanSee(
                $this->actor->workspaceId()->value,
                $input->clientId,
                Permission::CreativeView,
            );

            $isEligible = array_any(
                $eligible,
                static fn (OwnerCandidate $candidate): bool => $candidate->id === $input->ownerId,
            );

            if (! $isEligible) {
                throw CannotBeAssigned::toThatPerson('');
            }
        }

        $request = $this->requests->open(
            $this->actor->workspaceId()->value,
            $input,
            $this->actor->userId()->value,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CreativeRequested,
            auditableType: 'creative_request',
            auditableId: $request->id,
            summary: sprintf('Briefed %s: %s', $input->kind->label(), $input->title),
            changes: [
                'client_id' => $input->clientId,
                'kind' => $input->kind->value,
                /*
                 * Recorded because a request with no placement cannot have its versions checked against
                 * anything, and "why was this never validated" is a question somebody asks later.
                 */
                'provider' => $input->provider,
                'placement' => $input->placement,
            ],
        ));

        return $request;
    }
}
