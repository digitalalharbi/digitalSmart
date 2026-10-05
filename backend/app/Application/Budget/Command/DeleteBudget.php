<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Exception\InvalidBudget;

/**
 * Delete a draft.
 *
 * Only a draft. An approved budget has spend measured against it and a client who
 * agreed to it, so it is archived instead — a deleted commitment is a quarter nobody
 * can reconcile, and the first thing anyone asks for in a dispute.
 */
final readonly class DeleteBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Budget');
        }

        if ($existing->status !== BudgetStatus::Draft) {
            throw InvalidBudget::approvedMustBeArchived();
        }

        $this->budgets->delete($workspaceId, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::BudgetDeleted,
            auditableType: 'budget',
            auditableId: $id,
            summary: sprintf('Deleted draft budget "%s"', $existing->name),
            changes: ['amount_minor' => $existing->amountMinor, 'currency' => $existing->currency],
        ));
    }
}
