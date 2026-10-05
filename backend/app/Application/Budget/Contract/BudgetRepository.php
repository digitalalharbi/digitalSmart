<?php

declare(strict_types=1);

namespace App\Application\Budget\Contract;

use App\Application\Budget\DTO\AllocationInput;
use App\Application\Budget\DTO\BudgetInput;
use App\Application\Budget\DTO\BudgetView;
use App\Domain\Budget\BudgetStatus;

interface BudgetRepository
{
    /**
     * Budgets this actor may see.
     *
     * `$visibleProjectIds` of null means unrestricted. Otherwise a budget is visible
     * when it belongs to one of those projects, or to a client that has one of them —
     * because a client-level budget is agreed before any project spends it.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<BudgetStatus>  $statuses  empty means every status
     * @return list<BudgetView>
     */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $statuses = [],
        ?string $clientId = null,
    ): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?BudgetView;

    public function create(string $workspaceId, string $actorId, BudgetInput $input): BudgetView;

    public function update(string $workspaceId, string $id, BudgetInput $input): BudgetView;

    public function delete(string $workspaceId, string $id): void;

    public function approve(string $workspaceId, string $id, string $actorId): BudgetView;

    public function archive(string $workspaceId, string $id): BudgetView;

    /**
     * Replace the whole allocation tree.
     *
     * Replaced rather than merged, for the same reason a dashboard's widgets are: the
     * tree a reader sees is the whole plan, so an allocation they removed has to go.
     *
     * @param  list<AllocationInput>  $allocations  platform-level roots
     */
    public function replaceAllocations(string $workspaceId, string $id, array $allocations): BudgetView;
}
