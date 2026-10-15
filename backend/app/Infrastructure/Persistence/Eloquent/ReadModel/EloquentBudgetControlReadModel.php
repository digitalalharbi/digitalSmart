<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Budget\Contract\BudgetControlReadModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Every approved budget with its spend, in three statements however many budgets there are.
 *
 * The spend rule is the one `EloquentBudgetSpendReadModel` states and this repeats deliberately rather than
 * shares: campaign level only (summing levels triples spend), the budget's own period, and only accounts
 * bound to the budget's project — or, for a client-wide budget, any of that client's projects.
 */
final readonly class EloquentBudgetControlReadModel implements BudgetControlReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array<string, mixed>>
     */
    public function facts(string $workspaceId, ?array $visibleProjectIds, ?string $clientId, ?string $projectId): array
    {
        /*
         * The accounts a budget may count are resolved **first, once per budget**, and joined — never tested
         * per metric row with `m.external_account_id in (select …)`. That form is correlated with the budget, so
         * PostgreSQL runs the sub-select once for every candidate metric row: at a hundred clients and sixty
         * thousand daily rows it took four minutes, in six statements, which no statement count would ever
         * have shown. Joined, the accounts are a handful and the metric read is an index range per account.
         */
        $spend = DB::table(DB::raw('(
                select distinct bi.external_account_id
                from project_integration_bindings bi
                join projects pr on pr.id = bi.project_id
                where bi.workspace_id = b.workspace_id
                  and ((b.project_id is not null and bi.project_id = b.project_id)
                    or (b.project_id is null and pr.client_id = b.client_id))
            ) as acc'))
            ->join('daily_metrics as m', 'm.external_account_id', '=', 'acc.external_account_id')
            ->whereColumn('m.workspace_id', 'b.workspace_id')
            ->where('m.entity_type', 'campaign')
            ->whereColumn('m.date', '>=', 'b.period_start')
            ->whereColumn('m.date', '<=', 'b.period_end')
            ->selectRaw('sum(m.spend_minor) as spend_minor, count(m.spend_minor) as reported')
            ->selectRaw("string_agg(distinct m.currency, ',') as currencies")
            ->selectRaw("string_agg(distinct m.source, ',') as sources");

        $query = DB::table('budgets as b')
            ->join('clients as c', 'c.id', '=', 'b.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoinLateral($spend, 'sp')
            ->where('b.workspace_id', $workspaceId)
            ->where('b.status', 'approved')
            ->when($clientId !== null, static fn (Builder $q): Builder => $q->where('b.client_id', $clientId))
            ->when($projectId !== null, static fn (Builder $q): Builder => $q->where('b.project_id', $projectId));

        if ($visibleProjectIds !== null) {
            /* A client-wide budget is visible when the reader sees any project of that client. */
            $ids = $visibleProjectIds ?: ['00000000-0000-0000-0000-000000000000'];
            $query->where(static function (Builder $q) use ($ids, $workspaceId): void {
                $q->whereIn('b.project_id', $ids)
                    ->orWhere(static function (Builder $inner) use ($ids, $workspaceId): void {
                        $inner->whereNull('b.project_id')->whereIn('b.client_id', DB::table('projects')
                            ->select('client_id')->where('workspace_id', $workspaceId)->whereIn('id', $ids));
                    });
            });
        }

        /** @var list<stdClass> $rows */
        $rows = $query
            ->selectRaw('b.id, b.name, b.client_id, c.name as client_name, b.project_id, p.name as project_name')
            ->selectRaw('b.currency, b.amount_minor, b.period_start, b.period_end')
            ->selectRaw('(select coalesce(sum(a.amount_minor), 0) from budget_allocations a
                where a.budget_id = b.id and a.parent_id is null) as allocated_minor')
            ->selectRaw('sp.spend_minor, sp.reported, sp.currencies, sp.sources')
            ->orderBy('c.name')->orderBy('b.name')->orderBy('b.id')
            ->get()->all();

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (stdClass $r): string => (string) $r->id, $rows);

        $platform = $this->over($ids, "a.level = 'platform'", 'm.provider = a.provider');
        $campaign = $this->over($ids, "a.level = 'campaign' and a.campaign_id is not null", 'm.campaign_id = a.campaign_id');

        return array_map(static fn (stdClass $r): array => (array) $r + [
            'platform_over' => $platform[(string) $r->id] ?? 0,
            'campaign_over' => $campaign[(string) $r->id] ?? 0,
        ], $rows);
    }

    /**
     * Allocations the actual spend has already passed, counted per budget. One statement per level.
     *
     * @param  list<string>  $budgetIds
     * @return array<string, int>
     */
    private function over(array $budgetIds, string $allocationFilter, string $match): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::select(
            'select a.budget_id, count(*) as over
             from budget_allocations a
             join budgets b on b.id = a.budget_id
             where a.budget_id in ('.implode(',', array_fill(0, count($budgetIds), '?')).")
               and {$allocationFilter}
               and a.amount_minor < (
                 select coalesce(sum(m.spend_minor), 0) from daily_metrics m
                 where m.workspace_id = b.workspace_id and m.entity_type = 'campaign'
                   and m.date between b.period_start and b.period_end
                   and m.currency = b.currency and {$match})
             group by a.budget_id",
            $budgetIds,
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->budget_id] = (int) $row->over;
        }

        return $out;
    }
}
