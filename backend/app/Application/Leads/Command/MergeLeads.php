<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Leads\Exception\LeadsCannotBeMerged;
use App\Domain\Leads\LeadMergeCandidate;
use App\Domain\Leads\LeadMergePlan;
use App\Domain\Leads\LeadStage;

/**
 * Two leads a human knows are one person.
 *
 * The product question the roadmap left open — *whose history survives?* — is answered in `LeadMergePlan`, which
 * is where the rules belong and where they can be read in one place. This use case is the authorisation, the
 * two refusals that need the records rather than the rules, and the audit entry.
 *
 * **A merge is not reversible by anything this product offers.** There is no split: once the events are on one
 * record, undoing it would mean inventing a division nobody wrote down. So every check happens before anything
 * moves, the caller has to state the surviving stage rather than have one chosen for them, and the entry in the
 * audit log names both leads — the absorbed one by id, because afterwards it is the only way to find it.
 */
final readonly class MergeLeads
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $leadId, string $otherLeadId, LeadStage $stage, ?string $note = null): LeadView
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $first = $this->leads->find($workspaceId, $visible, $leadId);
        $second = $this->leads->find($workspaceId, $visible, $otherLeadId);

        if ($first === null || $second === null) {
            /*
             * Not found rather than forbidden, and deliberately indistinguishable from a lead outside this
             * actor's projects: otherwise the error tells somebody a lead they may not see exists.
             */
            throw ResourceNotFound::of('Lead');
        }

        if ($first->clientId !== $second->clientId) {
            throw LeadsCannotBeMerged::differentClients();
        }

        $plan = LeadMergePlan::of($this->candidate($first), $this->candidate($second));
        $plan->stageMustBeOneOfTheirs($stage);

        $merged = $this->leads->merge(
            $workspaceId,
            $plan,
            $stage,
            $this->actor->userId()->value,
            $note,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadsMerged,
            auditableType: 'lead',
            auditableId: $plan->survivor->id,
            summary: sprintf(
                'Merged two leads into one person, keeping the record from %s',
                $plan->survivor->receivedAt->format('Y-m-d'),
            ),
            changes: [
                'survivor' => $plan->survivor->id,
                'absorbed' => $plan->absorbed->id,
                'stage' => $stage->value,
                /*
                 * Both inheritances are recorded, because both change figures a client may already have been
                 * shown: the age decides every speed-of-response number, and the attribution decides which
                 * campaign gets the credit.
                 */
                'inherited_received_at' => $plan->inheritsReceivedAt,
                'inherited_attribution' => $plan->inheritsAttribution,
            ],
        ));

        return $merged;
    }

    private function candidate(LeadView $lead): LeadMergeCandidate
    {
        return new LeadMergeCandidate(
            id: $lead->id,
            sourceKind: $lead->sourceKind,
            stage: $lead->stage,
            evidence: $lead->evidence,
            receivedAt: $lead->receivedAt,
            isAlreadyMerged: $lead->mergedIntoId !== null,
        );
    }
}
