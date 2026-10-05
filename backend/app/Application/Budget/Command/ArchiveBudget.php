<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Retire a budget without deleting it.
 *
 * This is how an approved figure is superseded: the old budget stays, with the spend
 * recorded against it and the name of whoever approved it, and a new one is drafted.
 * Deleting it instead would make the quarter unreconcilable.
 */
final readonly class ArchiveBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): BudgetView
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Budget');
        }

        $budget = $this->budgets->archive($workspaceId, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::BudgetArchived,
            auditableType: 'budget',
            auditableId: $budget->id,
            summary: sprintf('Archived budget "%s"', $budget->name),
            changes: ['status' => ['before' => $existing->status->value, 'after' => $budget->status->value]],
        ));

        return $budget;
    }
}
