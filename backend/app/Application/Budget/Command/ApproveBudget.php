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
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Exception\InvalidBudget;

/**
 * Commit to a budget.
 *
 * Its own permission and its own use case, because this is the act the record exists
 * for: after it, the amount is fixed, pacing means something, and the audit trail says
 * who agreed what and when. A media buyer can plan all day and still not be the person
 * who does this.
 */
final readonly class ApproveBudget
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
        $this->guard->ensure(Permission::BudgetsApprove);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Budget');
        }

        $budget = match ($existing->status) {
            BudgetStatus::Approved => throw InvalidBudget::alreadyApproved(),
            BudgetStatus::Archived => throw InvalidBudget::cannotApproveArchived(),
            BudgetStatus::Draft => $this->budgets->approve($workspaceId, $id, $this->actor->userId()->value),
        };

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::BudgetApproved,
            auditableType: 'budget',
            auditableId: $budget->id,
            summary: sprintf(
                'Approved %s %s for "%s"',
                $budget->currency,
                number_format($budget->amountMinor / 100, 2),
                $budget->name,
            ),
            changes: [
                'amount_minor' => $budget->amountMinor,
                'currency' => $budget->currency,
                'period_start' => $budget->periodStart->format('Y-m-d'),
                'period_end' => $budget->periodEnd->format('Y-m-d'),
            ],
        ));

        return $budget;
    }
}
