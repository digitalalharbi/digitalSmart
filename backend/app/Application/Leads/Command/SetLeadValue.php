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
use App\Domain\Leads\Exception\InvalidLeadValue;
use App\Domain\Leads\LeadSourceKind;

/**
 * What somebody says a lead is worth.
 *
 * **Said, never inferred.** There is no "average deal size" anywhere in this product and this is the use case
 * that would have needed one. A value taken from an average is a figure about other leads wearing this one's
 * name, and once it is in the column nothing downstream can tell it from a quote somebody actually gave.
 *
 * **A CRM-sourced lead is refused**, exactly as its stage is. The CRM holds the deal's amount, this product
 * reads it, and a figure typed here would be overwritten by the next sync without anybody being told — while
 * both numbers had been quoted to a client in between.
 *
 * **Clearing it is allowed and is not the same as zero.** Null means nobody has said; zero would be a claim
 * that this lead is worth nothing, which is a judgement somebody has to make deliberately.
 */
final readonly class SetLeadValue
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $leadId, ?int $valueMinor, ?string $currency): LeadView
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $existing = $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId)
            ?? throw ResourceNotFound::of('Lead');

        if ($existing->sourceKind === LeadSourceKind::Crm) {
            throw InvalidLeadValue::ownedByTheCrm($existing->sourceDetail ?? '');
        }

        if ($valueMinor !== null) {
            if ($valueMinor < 0) {
                throw InvalidLeadValue::negative();
            }

            if ($currency === null || mb_strlen(trim($currency)) !== 3) {
                /* A number with no unit. The schema refuses it too; this says what is missing. */
                throw InvalidLeadValue::needsACurrency();
            }
        }

        $lead = $this->leads->setValue(
            $workspaceId,
            $leadId,
            $valueMinor,
            $valueMinor === null ? null : mb_strtoupper(trim($currency ?? '')),
            $this->actor->userId()->value,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadValued,
            auditableType: 'lead',
            auditableId: $leadId,
            summary: $valueMinor === null
                ? 'Cleared what a lead was said to be worth'
                : sprintf('Valued a lead at %s %s', number_format($valueMinor / 100, 2), (string) $currency),
            changes: [
                'from' => $existing->valueMinor,
                'to' => $valueMinor,
                'currency' => $lead->currency,
            ],
        ));

        return $lead;
    }
}
