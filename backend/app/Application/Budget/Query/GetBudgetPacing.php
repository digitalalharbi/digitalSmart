<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\Contract\BudgetSpendReadModel;
use App\Application\Budget\DTO\AllocationPacingView;
use App\Application\Budget\DTO\AllocationView;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Budget\DTO\PacingView;
use App\Application\Budget\DTO\SpendTotal;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Budget\AllocationLevel;
use App\Domain\Budget\Pacing;

/**
 * A budget against what was actually spent inside it.
 *
 * Three things this refuses to do, each of which would produce a plausible screen:
 *
 *   - **Pace a draft.** A draft is a proposal; a variance from a figure nobody agreed
 *     to is a number with no meaning. The reason is reported instead of a status.
 *   - **Pace spend it cannot total.** Spend covering two currencies, or sandbox figures
 *     mixed with live ones, arrives as a null with a reason — and then there is no
 *     pacing either, rather than pacing against a partial total.
 *   - **Compare an allocation with spend it cannot be matched to.** A campaign
 *     allocation written before the campaign exists has no campaign id, so it has no
 *     actual spend to compare — which is said, not shown as zero.
 */
final readonly class GetBudgetPacing
{
    public function __construct(
        private BudgetRepository $budgets,
        private BudgetSpendReadModel $spend,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(string $id): PacingView
    {
        $this->guard->ensure(Permission::BudgetsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $budget = $this->budgets->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($budget === null) {
            throw ResourceNotFound::of('Budget');
        }

        return $this->pacingFor($workspaceId, $budget);
    }

    /**
     * Pacing for a budget already loaded, which is how the alert list avoids
     * re-reading every one.
     */
    public function pacingFor(string $workspaceId, BudgetView $budget): PacingView
    {
        if (! $budget->status->isPaced()) {
            return new PacingView(
                budget: $budget,
                spend: SpendTotal::notReported(),
                pacing: null,
                unavailableReason: sprintf(
                    'This budget is %s. Pacing is only reported against an approved budget, because a variance from an unapproved figure means nothing.',
                    strtolower($budget->status->label()),
                ),
            );
        }

        $total = $this->spend->totalFor($workspaceId, $budget);

        return new PacingView(
            budget: $budget,
            spend: $total,
            pacing: $this->pacing($budget, $total),
            allocations: $this->allocationPacing($workspaceId, $budget),
            daily: $this->spend->dailyFor($workspaceId, $budget),
            unavailableReason: $total->hasValue() ? null : $total->unavailableReason,
        );
    }

    /**
     * @return list<AllocationPacingView>
     */
    private function allocationPacing(string $workspaceId, BudgetView $budget): array
    {
        $byProvider = $this->spend->byProvider($workspaceId, $budget);
        $byCampaign = $this->spend->byCampaign($workspaceId, $budget);
        $byAdGroup = $this->spend->byAdGroup($workspaceId, $budget);

        return $this->walk($budget, $budget->allocations, $byProvider, $byCampaign, $byAdGroup, null);
    }

    /**
     * @param  list<AllocationView>  $allocations
     * @param  array<string, SpendTotal>  $byProvider
     * @param  array<string, SpendTotal>  $byCampaign
     * @param  array<string, SpendTotal>  $byAdGroup
     * @return list<AllocationPacingView>
     */
    private function walk(
        BudgetView $budget,
        array $allocations,
        array $byProvider,
        array $byCampaign,
        array $byAdGroup,
        ?string $parentLabel,
    ): array {
        $rows = [];

        foreach ($allocations as $allocation) {
            [$spend, $reason] = $this->spendFor($allocation, $byProvider, $byCampaign, $byAdGroup);

            $rows[] = new AllocationPacingView(
                allocation: $allocation,
                spend: $spend,
                pacing: $reason === null ? $this->pacingForAllocation($budget, $allocation, $spend) : null,
                parentLabel: $parentLabel,
                unavailableReason: $reason,
            );

            foreach ($this->walk($budget, $allocation->children, $byProvider, $byCampaign, $byAdGroup, $allocation->label) as $child) {
                $rows[] = $child;
            }
        }

        return $rows;
    }

    /**
     * The spend that belongs to one allocation, and why it has none when it has none.
     *
     * @param  array<string, SpendTotal>  $byProvider
     * @param  array<string, SpendTotal>  $byCampaign
     * @param  array<string, SpendTotal>  $byAdGroup
     * @return array{SpendTotal, ?string}
     */
    private function spendFor(
        AllocationView $allocation,
        array $byProvider,
        array $byCampaign,
        array $byAdGroup,
    ): array {
        return match ($allocation->level) {
            AllocationLevel::Platform => [
                $byProvider[$allocation->provider->value] ?? SpendTotal::notReported(),
                null,
            ],
            AllocationLevel::Campaign => $allocation->campaignId === null
                ? [
                    SpendTotal::notReported(),
                    /*
                     * Planned before the campaign existed, which is normal. There is
                     * nothing to compare it with until the campaign is created and
                     * linked, and saying so is better than a zero that reads as "no
                     * spend".
                     */
                    'Not linked to a campaign yet, so there is no actual spend to compare.',
                ]
                : [$byCampaign[$allocation->campaignId] ?? SpendTotal::notReported(), null],
            AllocationLevel::AdGroup => $allocation->adGroupId === null
                ? [
                    SpendTotal::notReported(),
                    'Not linked to an ad group yet, so there is no actual spend to compare.',
                ]
                : [$byAdGroup[$allocation->adGroupId] ?? SpendTotal::notReported(), null],
        };
    }

    private function pacing(BudgetView $budget, SpendTotal $spend): ?Pacing
    {
        if (! $spend->hasValue()) {
            /*
             * No total, no pacing. Pacing against a partial figure would understate
             * spend, and understated spend is the one direction that gets a client
             * overcharged without anyone noticing.
             */
            return null;
        }

        return Pacing::compute(
            budgetMinor: $budget->amountMinor,
            spentMinor: (int) $spend->minorUnits,
            periodStart: $budget->periodStart,
            periodEnd: $budget->periodEnd,
            today: $this->clock->now(),
            currency: $budget->currency,
        );
    }

    private function pacingForAllocation(BudgetView $budget, AllocationView $allocation, SpendTotal $spend): ?Pacing
    {
        if (! $spend->hasValue()) {
            return null;
        }

        /*
         * An allocation inherits the budget's period: it is a share of that commitment
         * over those dates, not a separate schedule. Giving allocations their own dates
         * would let the parts cover a period the whole does not.
         */
        return Pacing::compute(
            budgetMinor: $allocation->amountMinor,
            spentMinor: (int) $spend->minorUnits,
            periodStart: $budget->periodStart,
            periodEnd: $budget->periodEnd,
            today: $this->clock->now(),
            currency: $allocation->currency,
        );
    }
}
