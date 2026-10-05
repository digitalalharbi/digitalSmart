<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Budget\Contract\BudgetRepository;
use App\Application\Budget\DTO\AllocationInput;
use App\Application\Budget\DTO\AllocationView;
use App\Application\Budget\DTO\BudgetInput;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Budget\AllocationLevel;
use App\Domain\Budget\BudgetStatus;
use App\Infrastructure\Persistence\Eloquent\Model\Budget;
use App\Infrastructure\Persistence\Eloquent\Model\BudgetAllocation;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentBudgetRepository implements BudgetRepository
{
    /**
     * Loaded on every read.
     *
     * The campaign and ad group names are included because the tree renders them, and
     * a plan with three platforms and twenty campaigns would otherwise be two dozen
     * queries to draw one screen.
     *
     * @var list<string>
     */
    private const RELATIONS = [
        'allocations',
        'allocations.campaign:id,name',
        'allocations.adGroup:id,name',
        'client:id,name',
        'project:id,name',
        'approver:id,name',
    ];

    public function __construct(private Clock $clock) {}

    /** @return list<BudgetView> */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $statuses = [],
        ?string $clientId = null,
    ): array {
        $query = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->with(self::RELATIONS);

        if ($statuses !== []) {
            $query->whereIn('status', array_map(static fn (BudgetStatus $s): string => $s->value, $statuses));
        }

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $budgets = $query
            // Current and future periods first: what somebody is managing today.
            ->orderByDesc('period_start')
            ->orderBy('name')
            ->get();

        return array_values($budgets->map(fn (Budget $b): BudgetView => $this->toView($b))->all());
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?BudgetView
    {
        $budget = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->with(self::RELATIONS)
            ->whereKey($id)
            ->first();

        return $budget instanceof Budget ? $this->toView($budget) : null;
    }

    public function create(string $workspaceId, string $actorId, BudgetInput $input): BudgetView
    {
        $budget = Budget::query()->create([
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'name' => $input->name,
            'period_start' => $input->periodStart->format('Y-m-d'),
            'period_end' => $input->periodEnd->format('Y-m-d'),
            'amount_minor' => $input->amountMinor,
            'currency' => strtoupper($input->currency),
            'status' => BudgetStatus::Draft,
            'notes' => $input->notes,
            'created_by' => $actorId,
        ]);

        return $this->toView($this->reload($budget));
    }

    public function update(string $workspaceId, string $id, BudgetInput $input): BudgetView
    {
        /** @var Budget $budget */
        $budget = Budget::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

        $budget->update([
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'name' => $input->name,
            'period_start' => $input->periodStart->format('Y-m-d'),
            'period_end' => $input->periodEnd->format('Y-m-d'),
            'amount_minor' => $input->amountMinor,
            'currency' => strtoupper($input->currency),
            'notes' => $input->notes,
        ]);

        return $this->toView($this->reload($budget));
    }

    public function delete(string $workspaceId, string $id): void
    {
        // Allocations cascade by the foreign key rather than by this method remembering.
        Budget::query()->where('workspace_id', $workspaceId)->whereKey($id)->delete();
    }

    public function approve(string $workspaceId, string $id, string $actorId): BudgetView
    {
        /** @var Budget $budget */
        $budget = Budget::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

        $budget->update([
            'status' => BudgetStatus::Approved,
            'approved_by' => $actorId,
            'approved_at' => $this->clock->now(),
        ]);

        return $this->toView($this->reload($budget));
    }

    public function archive(string $workspaceId, string $id): BudgetView
    {
        /** @var Budget $budget */
        $budget = Budget::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

        /*
         * The approver and the date stay. Who agreed to what is the reason this record
         * exists, and archiving is not a reason to forget it.
         */
        $budget->update(['status' => BudgetStatus::Archived]);

        return $this->toView($this->reload($budget));
    }

    /** @param list<AllocationInput> $allocations */
    public function replaceAllocations(string $workspaceId, string $id, array $allocations): BudgetView
    {
        return DB::transaction(function () use ($workspaceId, $id, $allocations): BudgetView {
            /** @var Budget $budget */
            $budget = Budget::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

            /*
             * The whole tree at once, inside a transaction, so it cannot be left
             * half-replaced. Deleting only the roots would rely on the cascade and leave
             * anything an earlier bug had orphaned.
             */
            BudgetAllocation::query()->where('budget_id', $budget->getKey())->delete();

            $this->insertNodes($workspaceId, (string) $budget->getKey(), null, $allocations);

            return $this->toView($this->reload($budget));
        });
    }

    /**
     * @param  list<AllocationInput>  $nodes
     */
    private function insertNodes(string $workspaceId, string $budgetId, ?string $parentId, array $nodes): void
    {
        $position = 0;

        foreach ($nodes as $node) {
            $allocation = BudgetAllocation::query()->create([
                'workspace_id' => $workspaceId,
                'budget_id' => $budgetId,
                'parent_id' => $parentId,
                'level' => $node->level,
                'provider' => $node->provider,
                'external_account_id' => $node->externalAccountId,
                'campaign_id' => $node->campaignId,
                'ad_group_id' => $node->adGroupId,
                'label' => $node->label,
                'amount_minor' => $node->amountMinor,
                'position' => $position++,
            ]);

            $this->insertNodes($workspaceId, $budgetId, (string) $allocation->getKey(), $node->children);
        }
    }

    /**
     * Budgets this actor may see.
     *
     * Applied in SQL rather than filtered afterwards, for the same reason as every
     * other read in this product: a budget outside the actor's projects is never in the
     * result set, so there is nothing to leak by accident. A client-level budget — one
     * with no project — is visible when the actor can see any project of that client,
     * because that is the budget those projects spend.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<Budget>
     */
    private function visibleQuery(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = Budget::query()->where('workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $query;
        }

        if ($visibleProjectIds === []) {
            // Assigned to nothing, so entitled to nothing.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($visibleProjectIds, $workspaceId): void {
            $scoped
                ->whereIn('project_id', $visibleProjectIds)
                ->orWhere(function (Builder $clientLevel) use ($visibleProjectIds, $workspaceId): void {
                    $clientLevel
                        ->whereNull('project_id')
                        ->whereIn('client_id', function (QueryBuilder $sub) use ($visibleProjectIds, $workspaceId): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                });
        });
    }

    private function reload(Budget $budget): Budget
    {
        /** @var Budget */
        return Budget::query()
            ->with(self::RELATIONS)
            ->whereKey($budget->getKey())
            ->firstOrFail();
    }

    private function toView(Budget $budget): BudgetView
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, BudgetAllocation> $allocations */
        $allocations = $budget->getRelation('allocations');

        return new BudgetView(
            id: (string) $budget->getKey(),
            clientId: (string) $budget->getAttribute('client_id'),
            clientName: (string) ($budget->client?->getAttribute('name') ?? 'Unknown client'),
            name: (string) $budget->getAttribute('name'),
            periodStart: $this->date($budget->getAttribute('period_start')),
            periodEnd: $this->date($budget->getAttribute('period_end')),
            amountMinor: (int) $budget->getAttribute('amount_minor'),
            currency: (string) $budget->getAttribute('currency'),
            status: $budget->getAttribute('status'),
            projectId: $budget->getAttribute('project_id') === null ? null : (string) $budget->getAttribute('project_id'),
            projectName: $budget->project?->getAttribute('name'),
            notes: $budget->getAttribute('notes'),
            approvedByName: $budget->approver?->getAttribute('name'),
            approvedAt: $budget->getAttribute('approved_at') === null ? null : $this->date($budget->getAttribute('approved_at')),
            allocations: $this->tree($allocations, null, (string) $budget->getAttribute('currency')),
            createdAt: $budget->getAttribute('created_at') === null ? null : $this->date($budget->getAttribute('created_at')),
            updatedAt: $budget->getAttribute('updated_at') === null ? null : $this->date($budget->getAttribute('updated_at')),
        );
    }

    /**
     * Rebuild the allocation tree from one flat collection.
     *
     * One pass per level rather than a query per node: a plan with three platforms and
     * twenty campaigns would otherwise be two dozen queries to draw one screen.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, BudgetAllocation>  $allocations
     * @return list<AllocationView>
     */
    private function tree(mixed $allocations, ?string $parentId, string $currency): array
    {
        $nodes = [];

        foreach ($allocations as $allocation) {
            $ownParent = $allocation->getAttribute('parent_id');
            $ownParent = $ownParent === null ? null : (string) $ownParent;

            if ($ownParent !== $parentId) {
                continue;
            }

            /** @var AllocationLevel $level */
            $level = $allocation->getAttribute('level');

            $nodes[] = new AllocationView(
                id: (string) $allocation->getKey(),
                level: $level,
                provider: $allocation->getAttribute('provider'),
                label: (string) $allocation->getAttribute('label'),
                amountMinor: (int) $allocation->getAttribute('amount_minor'),
                currency: $currency,
                externalAccountId: $allocation->getAttribute('external_account_id') === null
                    ? null
                    : (string) $allocation->getAttribute('external_account_id'),
                campaignId: $allocation->getAttribute('campaign_id') === null
                    ? null
                    : (string) $allocation->getAttribute('campaign_id'),
                campaignName: $allocation->campaign?->getAttribute('name'),
                adGroupId: $allocation->getAttribute('ad_group_id') === null
                    ? null
                    : (string) $allocation->getAttribute('ad_group_id'),
                adGroupName: $allocation->adGroup?->getAttribute('name'),
                children: $this->tree($allocations, (string) $allocation->getKey(), $currency),
            );
        }

        return $nodes;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable(is_string($value) ? $value : 'today');
    }
}
