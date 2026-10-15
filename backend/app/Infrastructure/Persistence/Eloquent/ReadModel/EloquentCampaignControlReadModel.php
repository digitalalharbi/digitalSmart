<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CampaignOwnership;
use App\Domain\Advertising\CampaignVitals;
use App\Domain\Advertising\EntityStatus;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Every campaign in the agency, with one window of figures beside it.
 *
 * **Two statements, whatever the campaign count.** The window aggregates arrive as lateral subqueries
 * rather than as a second pass per campaign, and the account's bindings the same way. The obvious shape
 * here — loop the campaigns, ask each one for its metrics — is the exact thing rule 71 forbids, and at
 * two thousand campaigns it is four thousand round trips.
 *
 * The result column varies per row, because a reach campaign's result is reach and a sales campaign's is
 * conversions. A `CASE` built from `CampaignObjective` keeps the mapping in the enum rather than hand-
 * written into SQL where it would drift the first time an objective is added.
 */
final readonly class EloquentCampaignControlReadModel implements CampaignControlReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<CampaignVitalsRow>
     */
    public function vitals(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $cap,
    ): array {
        $length = (int) $from->diff($to)->days + 1;
        $previousTo = $from->modify('-1 day');
        $previousFrom = $previousTo->modify(sprintf('-%d days', $length - 1));

        $query = $this->base($workspaceId, $visibleProjectIds, $filter)
            ->leftJoinLateral($this->window($from, $to), 'cur')
            ->leftJoinLateral($this->window($previousFrom, $previousTo), 'prev')
            ->addSelect([
                'cur.spend_minor as cur_spend',
                'cur.revenue_minor as cur_revenue',
                'cur.results as cur_results',
                'cur.reported_days as cur_days',
                'prev.spend_minor as prev_spend',
                'prev.results as prev_results',
            ])
            /*
             * Ordered by spend so a capped read keeps the campaigns that matter. Health cannot be the
             * sort here — it does not exist until the policy has run — so the cap has to be taken on a
             * figure the database holds, and "most money" is the least arbitrary one available.
             */
            ->orderByRaw('cur.spend_minor desc nulls last')
            ->orderBy('c.id')
            ->limit($cap);

        /** @var list<stdClass> $rows */
        $rows = $query->get()->all();

        return array_map(fn (stdClass $row): CampaignVitalsRow => $this->toRow($row), $rows);
    }

    public function spendByDay(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $days = [];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        $byCurrency = [];

        foreach (DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)->where('entity_type', 'campaign')
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereNotNull('spend_minor')
            ->groupBy('currency', 'date')
            ->selectRaw('currency, date, sum(spend_minor) as spend')
            ->get() as $row) {
            $byCurrency[(string) $row->currency][substr((string) $row->date, 0, 10)] = (int) $row->spend;
        }

        $out = [];

        foreach ($byCurrency as $currency => $series) {
            $out[$currency] = array_map(static fn (string $d): ?int => $series[$d] ?? null, $days);
        }

        return $out;
    }

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, list<int|null>>
     */
    public function trends(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($campaignIds === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)
            ->where('entity_type', 'campaign')
            ->whereIn('campaign_id', $campaignIds)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['campaign_id', 'date', 'spend_minor'])
            ->all();

        $days = [];

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        $byCampaign = [];

        foreach ($rows as $row) {
            $byCampaign[(string) $row->campaign_id][substr((string) $row->date, 0, 10)] = $this->nullableInt($row->spend_minor);
        }

        $out = [];

        foreach ($campaignIds as $id) {
            $out[$id] = array_map(static fn (string $d): ?int => $byCampaign[$id][$d] ?? null, $days);
        }

        return $out;
    }

    /** @param list<string>|null $visibleProjectIds */
    public function count(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
    ): int {
        return $this->base($workspaceId, $visibleProjectIds, $filter)->count();
    }

    /**
     * Campaigns, their account, their project and their client — narrowed to what the actor may see.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function base(
        string $workspaceId,
        ?array $visibleProjectIds,
        CampaignControlFilter $filter,
    ): Builder {
        $bindings = DB::table('project_integration_bindings as b')
            ->join('projects as bp', 'bp.id', '=', 'b.project_id')
            ->whereColumn('b.external_account_id', 'ea.id')
            ->selectRaw('count(*) as binding_count')
            ->selectRaw('min(bp.id::text) as only_project_id')
            ->when(
                $visibleProjectIds !== null,
                static fn (Builder $query): Builder => $visibleProjectIds === []
                    ? $query->whereRaw('false')
                    : $query->whereIn('b.project_id', $visibleProjectIds ?? []),
            );

        $query = DB::table('campaigns as c')
            ->join('external_accounts as ea', 'ea.id', '=', 'c.external_account_id')
            ->leftJoinLateral($bindings, 'bound')
            /*
             * The project a campaign belongs to: the explicit assignment, else the single binding. Joined
             * twice because those are two different rows, and coalescing the ids without coalescing the
             * names is how a row comes to show one project's id under another's name.
             */
            ->leftJoin('projects as ap', 'ap.id', '=', 'c.project_id')
            ->leftJoin('projects as ip', static function ($join): void {
                $join->on('ip.id', '=', DB::raw('bound.only_project_id::uuid'))
                    ->whereNull('c.project_id')
                    ->whereRaw('bound.binding_count = 1');
            })
            ->leftJoin('clients as cl', 'cl.id', '=', DB::raw('coalesce(ap.client_id, ip.client_id)'))
            ->where('c.workspace_id', $workspaceId)
            ->where(static function (Builder $inner): void {
                $inner->where('bound.binding_count', '>', 0)
                    ->orWhereNotExists(static fn (Builder $exists): Builder => $exists
                        ->from('project_integration_bindings as any_b')
                        ->whereColumn('any_b.external_account_id', 'ea.id'));
            })
            ->select([
                'c.id as campaign_id',
                'c.name as campaign_name',
                'c.provider',
                'c.status',
                'c.raw_status',
                'c.objective',
                'c.budget_minor',
                'c.budget_mode',
                'c.currency',
                'c.synced_at',
                'c.project_id as assigned_project_id',
                'ea.id as external_account_id',
                'ea.name as external_account_name',
                'bound.binding_count',
                DB::raw('coalesce(ap.id, ip.id) as project_id'),
                DB::raw('coalesce(ap.name, ip.name) as project_name'),
                'cl.id as client_id',
                'cl.name as client_name',
            ]);

        return $this->narrow($query, $filter);
    }

    private function narrow(Builder $query, CampaignControlFilter $filter): Builder
    {
        return $query
            ->when($filter->clientIds !== [], fn (Builder $q): Builder => $q->whereIn('cl.id', $filter->clientIds))
            ->when($filter->projectIds !== [], fn (Builder $q): Builder => $q->whereIn(
                DB::raw('coalesce(ap.id, ip.id)'),
                $filter->projectIds,
            ))
            ->when($filter->providers !== [], fn (Builder $q): Builder => $q->whereIn('c.provider', $filter->providers))
            ->when($filter->externalAccountIds !== [], fn (Builder $q): Builder => $q->whereIn(
                'ea.id',
                $filter->externalAccountIds,
            ))
            ->when($filter->statuses !== [], fn (Builder $q): Builder => $q->whereIn(
                'c.status',
                array_map(static fn (EntityStatus $s): string => $s->value, $filter->statuses),
            ))
            ->when($filter->objectives !== [], fn (Builder $q): Builder => $q->whereIn(
                'c.objective',
                array_map(static fn (CampaignObjective $o): string => $o->value, $filter->objectives),
            ))
            ->when($filter->tagIds !== [], fn (Builder $q): Builder => $q->whereExists(
                static fn (Builder $tag): Builder => $tag->from('taggings as tg')
                    ->whereIn('tg.tag_id', $filter->tagIds)
                    ->where(static function (Builder $on): void {
                        $on->where(static fn (Builder $x): Builder => $x->where('tg.entity_type', 'campaign')->whereColumn('tg.entity_id', 'c.id'))
                            ->orWhere(static fn (Builder $x): Builder => $x->where('tg.entity_type', 'project')
                                ->whereRaw('tg.entity_id = coalesce(ap.id, ip.id)'))
                            ->orWhere(static fn (Builder $x): Builder => $x->where('tg.entity_type', 'client')
                                ->whereColumn('tg.entity_id', 'cl.id'));
                    }),
            ))
            ->when(
                $filter->search !== null && $filter->search !== '',
                function (Builder $q) use ($filter): Builder {
                    /* Escaped, so a client called "100%" does not match every campaign in the agency. */
                    $term = '%'.addcslashes((string) $filter->search, '%_\\').'%';

                    return $q->where(static function (Builder $inner) use ($term): void {
                        $inner->where('c.name', 'ilike', $term)
                            ->orWhere('ea.name', 'ilike', $term)
                            ->orWhere('cl.name', 'ilike', $term)
                            ->orWhere(DB::raw('coalesce(ap.name, ip.name)'), 'ilike', $term);
                    });
                },
            );
    }

    /**
     * One window of figures for the campaign in the outer row.
     *
     * `sum()` over all-null returns null, which is what keeps "the provider reported nothing" distinct
     * from "the provider reported zero" — the single most important property in this file.
     */
    private function window(DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return DB::table('daily_metrics as m')
            ->whereColumn('m.campaign_id', 'c.id')
            ->whereBetween('m.date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('sum(m.spend_minor) as spend_minor')
            ->selectRaw('sum(m.revenue_minor) as revenue_minor')
            ->selectRaw('count(distinct m.date) as reported_days')
            ->selectRaw($this->resultExpression().' as results');
    }

    /**
     * The result column, chosen per row by the campaign's objective.
     *
     * Built from the enum rather than typed out, so adding an objective cannot leave this behind.
     */
    private function resultExpression(): string
    {
        $cases = [];

        foreach (CampaignObjective::cases() as $objective) {
            $cases[] = sprintf(
                "when '%s' then m.%s",
                $objective->value,
                $objective->resultColumn(),
            );
        }

        return 'sum(case c.objective '.implode(' ', $cases).' end)';
    }

    private function toRow(stdClass $row): CampaignVitalsRow
    {
        $objective = CampaignObjective::from((string) $row->objective);
        $bindingCount = (int) $row->binding_count;
        $assigned = $this->nullableString($row->assigned_project_id);

        $ownership = match (true) {
            $assigned !== null => CampaignOwnership::Assigned,
            $bindingCount === 1 => CampaignOwnership::Implied,
            $bindingCount === 0 => CampaignOwnership::Unbound,
            default => CampaignOwnership::Ambiguous,
        };

        $syncedAt = $this->toDate($row->synced_at);

        return new CampaignVitalsRow(
            campaignId: (string) $row->campaign_id,
            campaignName: (string) $row->campaign_name,
            provider: (string) $row->provider,
            externalAccountId: (string) $row->external_account_id,
            externalAccountName: (string) $row->external_account_name,
            status: EntityStatus::from((string) $row->status),
            rawStatus: $this->nullableString($row->raw_status),
            objective: $objective,
            ownership: $ownership,
            projectId: $this->nullableString($row->project_id),
            projectName: $this->nullableString($row->project_name),
            clientId: $this->nullableString($row->client_id),
            clientName: $this->nullableString($row->client_name),
            currency: $this->nullableString($row->currency),
            budgetMinor: $this->nullableInt($row->budget_minor),
            budgetMode: (string) ($row->budget_mode ?? 'unknown'),
            vitals: new CampaignVitals(
                status: EntityStatus::from((string) $row->status),
                objective: $objective,
                reportedDays: (int) ($row->cur_days ?? 0),
                spendMinor: $this->nullableInt($row->cur_spend),
                results: $this->nullableFloat($row->cur_results),
                revenueMinor: $this->nullableInt($row->cur_revenue),
                previousSpendMinor: $this->nullableInt($row->prev_spend),
                previousResults: $this->nullableFloat($row->prev_results),
                lastSyncedAt: $syncedAt,
            ),
            lastSyncedAt: $syncedAt,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
