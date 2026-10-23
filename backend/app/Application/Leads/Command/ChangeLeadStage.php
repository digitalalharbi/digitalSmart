<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadView;
use App\Application\Leads\Service\LeadProgressAnnouncer;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Service\ConversionDispatcher;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Leads\Exception\InvalidLeadTransition;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Tracking\TrackingEventName;

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
 *
 * **A lead that came from a CRM is refused**, and that is the CRM bridge's whole position in one place: the
 * system a lead came from owns its stage, this product reads it, and a move made here would be undone by the
 * next sync without anybody being told. Two systems both claiming to own a stage is how a sales team loses a
 * day's work — and whichever wrote last wins, which makes the figure a client sees depend on the order of two
 * jobs. See `CrmStageMeaning` and docs/CRM.md.
 */
final readonly class ChangeLeadStage
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private ConversionDispatcher $conversions,
        private LeadProgressAnnouncer $progress,
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

        if ($existing->sourceKind === LeadSourceKind::Crm) {
            /*
             * Refused rather than overwritten. The reason names where to do it instead, because somebody working
             * a pipeline needs an instruction and not a rule.
             */
            throw InvalidLeadTransition::ownedByTheCrm($existing->sourceDetail ?? '');
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

        /*
         * A later moment in the lead's life may be a conversion an advertiser wants told. Only a real change counts, and only
         * these two stages mean something to a provider; the dispatcher records what it did with each mapping either way.
         */
        if ($existing->stage !== $stage) {
            $event = match ($stage) {
                LeadStage::Qualified => TrackingEventName::LeadQualified,
                LeadStage::Won => TrackingEventName::SaleCompleted,
                default => null,
            };

            if ($event !== null) {
                $this->conversions->forLead($workspaceId, $leadId, $event);
                $this->progress->announce($lead, $event === TrackingEventName::SaleCompleted ? NotificationKind::LeadSaleCompleted : NotificationKind::LeadQualified);
            }
        }

        return $lead;
    }
}
