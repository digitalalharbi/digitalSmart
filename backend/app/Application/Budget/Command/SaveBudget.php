<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\BudgetInput;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Budget\Exception\InvalidBudget;

/**
 * Create or change a budget.
 *
 * One rule carries the whole feature: **an approved budget's amount and period cannot
 * be edited.** A budget record exists to say what a client agreed to, and a figure that
 * can be quietly revised afterwards says nothing. Changing an approved amount means
 * archiving it and approving a new one, which leaves both on the record.
 *
 * Every change is recorded with its before and after. That is the immutable change log
 * the governance hierarchy needs, and it lives in the audit trail rather than a second
 * history table that would need keeping honest separately.
 */
final readonly class SaveBudget
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(BudgetInput $input, ?string $id = null): BudgetView
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($input->amountMinor <= 0) {
            throw InvalidBudget::amountMustBePositive();
        }

        if ($input->periodEnd < $input->periodStart) {
            throw InvalidBudget::periodEndsBeforeItStarts();
        }

        if ($id === null) {
            $budget = $this->budgets->create($workspaceId, $this->actor->userId()->value, $input);

            $this->audit->record(new AuditEntry(
                workspaceId: $this->actor->workspaceId(),
                actorId: $this->actor->userId(),
                action: AuditAction::BudgetCreated,
                auditableType: 'budget',
                auditableId: $budget->id,
                summary: sprintf('Drafted budget "%s"', $budget->name),
                changes: $this->snapshot($budget),
            ));

            return $budget;
        }

        $existing = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Budget');
        }

        if (! $existing->status->allowsAllocationChange()) {
            throw InvalidBudget::archivedCannotChange();
        }

        /*
         * The name, the project and the notes may always change — those are labels and
         * working notes. The amount, the period and the currency are the commitment.
         */
        if (! $existing->status->allowsAmountChange() && $this->commitmentChanged($existing, $input)) {
            throw InvalidBudget::amountIsFixedOnceApproved();
        }

        $budget = $this->budgets->update($workspaceId, $id, $input);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::BudgetUpdated,
            auditableType: 'budget',
            auditableId: $budget->id,
            summary: sprintf('Changed budget "%s"', $budget->name),
            changes: ['before' => $this->snapshot($existing), 'after' => $this->snapshot($budget)],
        ));

        return $budget;
    }

    private function commitmentChanged(BudgetView $existing, BudgetInput $input): bool
    {
        return $existing->amountMinor !== $input->amountMinor
            || strtoupper($input->currency) !== $existing->currency
            || $existing->periodStart->format('Y-m-d') !== $input->periodStart->format('Y-m-d')
            || $existing->periodEnd->format('Y-m-d') !== $input->periodEnd->format('Y-m-d');
    }

    /**
     * What the change log records.
     *
     * The notes are left out deliberately: a working note is not part of the
     * commitment, and copying it into an append-only log makes it undeletable.
     *
     * @return array<string, mixed>
     */
    private function snapshot(BudgetView $budget): array
    {
        return [
            'name' => $budget->name,
            'client_id' => $budget->clientId,
            'project_id' => $budget->projectId,
            'amount_minor' => $budget->amountMinor,
            'currency' => $budget->currency,
            'period_start' => $budget->periodStart->format('Y-m-d'),
            'period_end' => $budget->periodEnd->format('Y-m-d'),
            'status' => $budget->status->value,
        ];
    }
}
