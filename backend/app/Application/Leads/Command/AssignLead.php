<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadOwnerDirectory;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadOwnerCandidate;
use App\Application\Leads\DTO\LeadView;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Leads\Exception\LeadCannotBeOwned;
use App\Domain\Notifications\NotificationKind;

/**
 * Put a lead in somebody's name, or in nobody's.
 *
 * **The owner has to be somebody who can see the lead.** This is the one check that distinguishes this from a
 * field update, and the reason is specific: a lead carries a prospect's name and phone number, so assigning one
 * to somebody whose projects do not reach that client would put their name in the owner column of a record they
 * can never open — and the notification telling them would be correctly suppressed by the same visibility rule,
 * so nobody would ever find out. The owner column would name a person who does not know.
 *
 * **Unassigning is a first-class outcome**, for the reason the task board gives: a pipeline that can only ever
 * add an owner accumulates leads belonging to whoever touched them first, and then every workload figure
 * describes a team that does not exist.
 *
 * **A lead that came from a CRM can still be assigned here.** Ownership is not a stage: the CRM owns where a
 * deal stands in its own pipeline, and who in this agency is chasing it is this product's business.
 */
final readonly class AssignLead
{
    public function __construct(
        private LeadRepository $leads,
        private LeadOwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Announce $announce,
    ) {}

    public function __invoke(string $leadId, ?string $ownerId): LeadView
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $existing = $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId)
            ?? throw ResourceNotFound::of('Lead');

        if ($ownerId !== null) {
            $candidates = $this->owners->peopleWhoCanSee($workspaceId, $existing->clientId);

            $isEligible = array_any(
                $candidates,
                static fn (LeadOwnerCandidate $candidate): bool => $candidate->id === $ownerId,
            );

            if (! $isEligible) {
                throw LeadCannotBeOwned::byThatPerson($existing->clientName);
            }
        }

        $assigned = $this->leads->assign($workspaceId, $leadId, $ownerId, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadAssigned,
            auditableType: 'lead',
            auditableId: $leadId,
            summary: $ownerId === null
                ? 'Took a lead out of somebody\'s name'
                : sprintf('Put a lead in %s\'s name', $assigned->ownerName ?? 'somebody'),
            changes: ['from' => $existing->ownerId, 'to' => $ownerId],
        ));

        if ($ownerId !== null) {
            /*
             * Only the new owner, and never the actor — `Announce` drops them. Taking a lead yourself does not
             * need announcing to yourself, and unassigning tells nobody: there is no new owner, and telling the
             * person it was taken from would be a complaint rather than a task.
             *
             * The body carries no contact details. It says a lead is waiting and how long it has been waiting,
             * which is the fact that makes somebody open it; the prospect's phone number belongs behind the
             * permission check that guards the record, not in a notification row.
             */
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::LeadAssigned,
                title: $assigned->name ?? $assigned->sourceKind->label(),
                subjectId: $assigned->id,
                body: $assigned->firstContactedAt !== null
                    ? 'Already contacted.'
                    : sprintf(
                        'Nobody has answered this enquiry yet. It arrived %s.',
                        $assigned->receivedAt->format('Y-m-d'),
                    ),
                onlyUserId: $ownerId,
                aboutProjectId: $assigned->projectId,
                aboutClientId: $assigned->clientId,
            ));
        }

        return $assigned;
    }
}
