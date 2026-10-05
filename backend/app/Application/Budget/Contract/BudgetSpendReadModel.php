<?php

declare(strict_types=1);

namespace App\Application\Budget\Contract;

use App\Application\Budget\DTO\BudgetView;
use App\Application\Budget\DTO\SpendTotal;

/**
 * Actual spend for a budget, read from the synced fact table.
 *
 * Separate from the budget repository because it answers a different question with a
 * different authority: the repository owns what was agreed, this owns what happened.
 */
interface BudgetSpendReadModel
{
    /** Total spend inside the budget's scope and period. */
    public function totalFor(string $workspaceId, BudgetView $budget): SpendTotal;

    /**
     * Spend per provider, for comparing against platform allocations.
     *
     * @return array<string, SpendTotal> keyed by provider value
     */
    public function byProvider(string $workspaceId, BudgetView $budget): array;

    /**
     * Spend per campaign, for comparing against campaign allocations.
     *
     * @return array<string, SpendTotal> keyed by campaign id
     */
    public function byCampaign(string $workspaceId, BudgetView $budget): array;

    /**
     * Spend per ad group.
     *
     * @return array<string, SpendTotal> keyed by ad group id
     */
    public function byAdGroup(string $workspaceId, BudgetView $budget): array;

    /**
     * Cumulative spend by date inside the period, for a pacing chart.
     *
     * @return list<array{date: string, spendMinor: int}>
     */
    public function dailyFor(string $workspaceId, BudgetView $budget): array;
}
