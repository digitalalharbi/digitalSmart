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
use App\Domain\Leads\Exception\InvalidLeadTransition;
use App\Domain\Leads\LeadStage;

/**
 * Move a lead through the pipeline.
 *
 * One rule enforced here rather than in the database, because it is about intent rather than shape:
 * **a disqualification must say why.** "Not a real enquiry", "out of area", "price" and "wrong product" lead
 * to four different conversations with a client paying for leads, and a disqualified pile with no reasons is
 * the single least useful thing an agency can hand back.
 *
 * Every transition is audited with both stages. A pipeline review asks "who qualified this, and when", and
 * the answer has to survive the lead being closed afterwards.
 */
final readonly class ChangeLeadStage
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        string $leadId,
        LeadStage $stage,
        ?string $reason = null,
        ?string $note = null,
    ): LeadView {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId);

        if ($existing === null) {
            throw ResourceNotFound::of('Lead');
        }

        if ($stage === LeadStage::Disqualified && ($reason === null || trim($reason) === '')) {
            throw InvalidLeadTransition::disqualificationNeedsAReason();
        }

        $lead = $this->leads->changeStage(
            $workspaceId,
            $leadId,
            $stage,
            $this->actor->userId()->value,
            $reason === null ? null : trim($reason),
            $note,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadStageChanged,
            auditableType: 'lead',
            auditableId: $leadId,
            summary: sprintf('Moved a lead from %s to %s', $existing->stage->label(), $stage->label()),
            changes: [
                'from' => $existing->stage->value,
                'to' => $stage->value,
                'reason' => $reason,
            ],
        ));

        return $lead;
    }
}
