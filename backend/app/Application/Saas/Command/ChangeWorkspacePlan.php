<?php

declare(strict_types=1);

namespace App\Application\Saas\Command;

use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Saas\Exception\EntitlementChangeRefused;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Workspace\WorkspaceId;

/**
 * Put a workspace on a plan. An operator's act, not a workspace member's: nothing inside the product calls it, and it needs a
 * name and a reason because the people in the workspace will ask why their screens changed.
 *
 * **Data is never touched.** A downgrade that leaves a workspace with more clients than its new plan allows keeps every one of
 * them; it only refuses the next. A feature the plan no longer has stops answering; nothing it made is deleted.
 */
final readonly class ChangeWorkspacePlan
{
    public function __construct(
        private EntitlementStore $store,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $workspaceId, string $planCode, string $operator, string $reason): void
    {
        if (trim($operator) === '' || trim($reason) === '') {
            throw EntitlementChangeRefused::needsOperatorAndReason();
        }

        if (! $this->store->planExists($planCode)) {
            throw EntitlementChangeRefused::unknownPlan($planCode);
        }

        $before = $this->store->forWorkspace($workspaceId)->planCode;
        $this->store->assignPlan($workspaceId, $planCode, $operator);

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($workspaceId),
            actorId: null,
            action: AuditAction::WorkspacePlanChanged,
            auditableType: 'workspace',
            auditableId: $workspaceId,
            summary: sprintf('%s moved the workspace from the %s plan to the %s plan.', $operator, $before, $planCode),
            changes: ['plan' => ['from' => $before, 'to' => $planCode], 'operator' => $operator, 'reason' => $reason],
        ));
    }
}
