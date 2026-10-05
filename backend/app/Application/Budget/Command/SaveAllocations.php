<?php

declare(strict_types=1);

namespace App\Application\Budget\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\AllocationInput;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\ValueObject\Money;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Budget\AllocationLevel;
use App\Domain\Budget\Exception\InvalidAllocation;
use App\Domain\Budget\Exception\InvalidBudget;

/**
 * Replace a budget's allocation tree.
 *
 * The governance hierarchy is the feature, and it is only worth anything if the parts
 * cannot add up to more than the whole. So every branch is checked on the way down:
 * platform allocations against the approved budget, campaign allocations against their
 * platform, ad groups against their campaign.
 *
 * The parts are allowed to add up to *less*. An unallocated remainder is a decision
 * nobody has made yet — a plan in progress, not an error — and it is reported rather
 * than silently distributed.
 *
 * Replaced rather than merged, like a dashboard's widgets: the tree a reader sees is
 * the whole plan, so an allocation they removed has to go.
 */
final readonly class SaveAllocations
{
    public function __construct(
        private BudgetRepository $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /** @param list<AllocationInput> $allocations platform-level roots */
    public function __invoke(string $id, array $allocations): BudgetView
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Budget');
        }

        if (! $existing->status->allowsAllocationChange()) {
            throw InvalidBudget::archivedCannotChange();
        }

        $this->assertCoherent($allocations, $existing);

        $budget = $this->budgets->replaceAllocations($workspaceId, $id, $allocations);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::BudgetAllocationsChanged,
            auditableType: 'budget',
            auditableId: $budget->id,
            summary: sprintf(
                'Reallocated budget "%s": %s of %s %s now allocated',
                $budget->name,
                number_format($budget->allocatedMinor() / 100, 2),
                number_format($budget->amountMinor / 100, 2),
                $budget->currency,
            ),
            changes: [
                'before' => $this->flatten($existing->allocations),
                'after' => $this->flatten($budget->allocations),
            ],
        ));

        return $budget;
    }

    /**
     * @param  list<AllocationInput>  $allocations
     *
     * @throws InvalidAllocation
     */
    private function assertCoherent(array $allocations, BudgetView $budget): void
    {
        $total = 0;

        foreach ($allocations as $allocation) {
            if ($allocation->level !== AllocationLevel::Platform) {
                throw InvalidAllocation::rootMustBeAPlatform($allocation->level->label());
            }

            $this->assertNode($allocation, $budget->currency);
            $total += $allocation->amountMinor;
        }

        if ($total > $budget->amountMinor) {
            throw InvalidAllocation::exceedsBudget(
                Money::of($total, $budget->currency)->toDecimal().' '.$budget->currency,
                Money::of($budget->amountMinor, $budget->currency)->toDecimal().' '.$budget->currency,
            );
        }
    }

    /** @throws InvalidAllocation */
    private function assertNode(AllocationInput $allocation, string $currency): void
    {
        if ($allocation->amountMinor <= 0) {
            throw InvalidAllocation::amountMustBePositive($allocation->label);
        }

        $expectedChildLevel = match ($allocation->level) {
            AllocationLevel::Platform => AllocationLevel::Campaign,
            AllocationLevel::Campaign => AllocationLevel::AdGroup,
            AllocationLevel::AdGroup => null,
        };

        foreach ($allocation->children as $child) {
            if ($expectedChildLevel === null || $child->level !== $expectedChildLevel) {
                throw InvalidAllocation::wrongParentLevel(
                    $child->level->label(),
                    ($child->level->parent() ?? AllocationLevel::Platform)->label(),
                    $allocation->level->label(),
                );
            }

            /*
             * A child under a different platform than its parent would make the platform
             * level a lie: the sum under "Meta" would include Google spend.
             */
            if ($child->provider !== $allocation->provider) {
                throw InvalidAllocation::childUnderDifferentProvider(
                    $child->label,
                    $child->provider->label(),
                    $allocation->provider->label(),
                );
            }

            $this->assertNode($child, $currency);
        }

        $childTotal = $allocation->childTotalMinor();

        if ($childTotal > $allocation->amountMinor) {
            throw InvalidAllocation::exceedsParent(
                $allocation->label,
                Money::of($childTotal, $currency)->toDecimal().' '.$currency,
                Money::of($allocation->amountMinor, $currency)->toDecimal().' '.$currency,
            );
        }
    }

    /**
     * The tree as a flat list for the change log.
     *
     * Append-only entries are read long after the fact, so the log records labels and
     * amounts rather than ids nobody can resolve once a campaign is deleted.
     *
     * @param  list<\App\Application\Budget\DTO\AllocationView>  $allocations
     * @return list<array<string, mixed>>
     */
    private function flatten(array $allocations, ?string $parentLabel = null): array
    {
        $rows = [];

        foreach ($allocations as $allocation) {
            $rows[] = [
                'level' => $allocation->level->value,
                'provider' => $allocation->provider->value,
                'label' => $allocation->label,
                'parent' => $parentLabel,
                'amount_minor' => $allocation->amountMinor,
            ];

            foreach ($this->flatten($allocation->children, $allocation->label) as $child) {
                $rows[] = $child;
            }
        }

        return $rows;
    }
}
