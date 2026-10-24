<?php

declare(strict_types=1);

namespace App\Application\Saas\Command;

use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Saas\Exception\EntitlementChangeRefused;
use App\Application\Shared\Contract\AuditRecorder;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;
use App\Domain\Workspace\WorkspaceId;

/**
 * Change one feature or one limit for one workspace, above its plan. Every override is a new row — the previous value stays on
 * the record — and `inherit` hands the key back to the plan.
 *
 * A feature every workspace has cannot be switched off: it is what makes the product a product, and turning it off would leave
 * somebody signed in to a workspace with no overview.
 */
final readonly class OverrideEntitlement
{
    public function __construct(
        private EntitlementStore $store,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $workspaceId, string $kind, string $key, string $value, string $operator, string $reason): string
    {
        if (trim($operator) === '' || trim($reason) === '') {
            throw EntitlementChangeRefused::needsOperatorAndReason();
        }

        if ($kind === 'feature') {
            $feature = Feature::tryFrom($key) ?? throw EntitlementChangeRefused::unknownKey($kind, $key);

            if (! in_array($value, ['on', 'off', 'inherit'], true)) {
                throw EntitlementChangeRefused::badValue($kind, $value);
            }

            if ($feature->isEverywhere()) {
                throw EntitlementChangeRefused::everywhere($key);
            }
        } elseif ($kind === 'limit') {
            Limit::tryFrom($key) ?? throw EntitlementChangeRefused::unknownKey($kind, $key);

            if (! in_array($value, ['unlimited', 'inherit'], true) && ! ctype_digit($value)) {
                throw EntitlementChangeRefused::badValue($kind, $value);
            }
        } else {
            throw EntitlementChangeRefused::unknownKind($kind);
        }

        $previous = $this->store->override($workspaceId, $kind, $key, $value, $reason, $operator);

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($workspaceId),
            actorId: null,
            action: AuditAction::WorkspaceEntitlementOverridden,
            auditableType: 'workspace',
            auditableId: $workspaceId,
            summary: sprintf('%s set %s "%s" to %s (was %s).', $operator, $kind, $key, $value, $previous),
            changes: ['kind' => $kind, 'key' => $key, 'from' => $previous, 'to' => $value, 'operator' => $operator, 'reason' => $reason],
        ));

        return $previous;
    }
}
