<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadInput;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Leads\LeadSourceKind;

/**
 * Record a lead.
 *
 * Deliberately thin, and deliberately the **only** way a lead enters this product. There is no path from a
 * counted signal to a row here: the measurement layer counts WhatsApp taps and form starts, and none of them
 * can reach this method, because `LeadSourceKind` has no member for them. A click is not a lead.
 *
 * The audit entry names the source kind, so "where did these forty leads come from" is answerable from the
 * record rather than from somebody's memory of what was imported that week.
 */
final readonly class RecordLead
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(LeadInput $input): LeadView
    {
        $this->guard->ensure(Permission::LeadsManage);

        $lead = $this->leads->record($this->actor->workspaceId()->value, $input);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadRecorded,
            auditableType: 'lead',
            auditableId: $lead->id,
            summary: sprintf('Recorded a %s lead', $input->sourceKind->label()),
            changes: [
                'source_kind' => $input->sourceKind->value,
                'source_detail' => $input->sourceDetail,
                /*
                 * Recorded so a figure can be defended later: a hand-entered lead is a real lead and must
                 * never be reported as a campaign result, and this is where that is visible.
                 */
                'is_measured' => $input->sourceKind->isMeasured(),
                'attribution_evidence' => $input->attribution->evidence->value,
                'client_id' => $input->clientId,
            ],
        ));

        return $lead;
    }

    /**
     * The source kinds a person may record by hand.
     *
     * Everything else arrives from an integration. Exposed so a form can offer the right list rather than all
     * eight, and so `import` and `crm` cannot be claimed by somebody typing into a dialog.
     *
     * @return list<LeadSourceKind>
     */
    public static function manualSourceKinds(): array
    {
        return [
            LeadSourceKind::Manual,
            LeadSourceKind::PhoneCall,
            LeadSourceKind::WhatsAppMessage,
            LeadSourceKind::Chat,
            LeadSourceKind::FormSubmission,
        ];
    }
}
