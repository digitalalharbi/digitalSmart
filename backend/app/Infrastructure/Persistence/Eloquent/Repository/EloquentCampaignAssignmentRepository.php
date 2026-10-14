<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Advertising\Contract\CampaignAssignmentRepository;
use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Domain\Advertising\CampaignOwnership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Campaign ownership, resolved in SQL.
 *
 * **One statement, whatever the campaign count.** Rule 71 forbids "for each campaign, query its bindings",
 * and that shape is the obvious one here: ownership depends on how many projects an account is bound to.
 * It is computed instead as a lateral aggregate per account and joined once, so a page of fifty campaigns
 * costs the same two queries as a page of one.
 */
final readonly class EloquentCampaignAssignmentRepository implements CampaignAssignmentRepository
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<CampaignOwnership>  $only
     * @return list<CampaignOwnershipView>
     */
    public function page(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $only,
        int $limit,
        int $offset,
    ): array {
        /*
         * Wrapped, because ownership is a computed column and Postgres will not let a `WHERE` refer to a
         * select alias in the same statement. One subquery keeps the expression written once — three
         * copies of the rule would be three chances for the heading to disagree with the rows.
         */
        $query = DB::query()->fromSub($this->base($workspaceId, $visibleProjectIds), 'resolved');

        if ($only !== []) {
            $query->whereIn(
                'ownership',
                array_map(static fn (CampaignOwnership $state): string => $state->value, $only),
            );
        }

        /** @var list<stdClass> $rows */
        $rows = $query
            ->orderByDesc('synced_at')
            ->orderBy('campaign_id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->all();

        return array_map(fn (stdClass $row): CampaignOwnershipView => $this->toView($row), $rows);
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int>
     */
    public function countByOwnership(string $workspaceId, ?array $visibleProjectIds): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::query()
            ->fromSub($this->base($workspaceId, $visibleProjectIds), 'resolved')
            ->selectRaw('ownership, count(*) as total')
            ->groupBy('ownership')
            ->get()
            ->all();

        /* Every state present, so a zero reads as "none" rather than as an absent key. */
        $counts = array_fill_keys(CampaignOwnership::values(), 0);

        foreach ($rows as $row) {
            $counts[(string) $row->ownership] = (int) $row->total;
        }

        return $counts;
    }

    /** @param list<string>|null $visibleProjectIds */
    public function find(
        string $workspaceId,
        ?array $visibleProjectIds,
        string $campaignId,
    ): ?CampaignOwnershipView {
        $row = DB::query()
            ->fromSub($this->base($workspaceId, $visibleProjectIds), 'resolved')
            ->where('campaign_id', $campaignId)
            ->first();

        return $row instanceof stdClass ? $this->toView($row) : null;
    }

    public function assign(string $workspaceId, string $campaignId, ?string $projectId): void
    {
        DB::table('campaigns')
            ->where('workspace_id', $workspaceId)
            ->where('id', $campaignId)
            ->update(['project_id' => $projectId]);
    }

    /**
     * The base query: campaigns, their account, the account's bound projects, and the explicit assignment.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function base(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $bindings = DB::table('project_integration_bindings as b')
            ->join('projects as bp', 'bp.id', '=', 'b.project_id')
            ->whereColumn('b.external_account_id', 'ea.id')
            ->selectRaw("json_agg(json_build_object('id', bp.id, 'name', bp.name) order by bp.name) as projects")
            ->selectRaw('count(*) as binding_count')
            /*
             * Only projects this actor may read. A campaign on an account bound solely to projects somebody
             * cannot see must not appear to them as assignable — publishing the candidate list is how the
             * assignment command decides what is legal, so a leak here is a leak there.
             */
            ->when(
                /*
                 * Null is "every project", `[]` is "none". Collapsing them is how a member assigned to
                 * nothing would be shown the whole agency's campaigns.
                 */
                $visibleProjectIds !== null,
                static fn (Builder $query): Builder => $visibleProjectIds === []
                    ? $query->whereRaw('false')
                    : $query->whereIn('b.project_id', $visibleProjectIds ?? []),
            );

        return DB::table('campaigns as c')
            ->join('external_accounts as ea', 'ea.id', '=', 'c.external_account_id')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'p.client_id')
            /*
             * Lateral, because the subquery correlates on `ea.id`. A plain `joinSub` cannot see the outer
             * row and Postgres rejects it; a left lateral join always yields its one aggregate row, so an
             * account with no bindings arrives as `binding_count = 0` rather than disappearing.
             */
            ->leftJoinLateral($bindings, 'bound')
            ->where('c.workspace_id', $workspaceId)
            /*
             * A campaign whose account reaches no visible project is not this actor's to see at all. The
             * `binding_count = 0` case that survives this is an account bound to nothing by anyone, which
             * is a real review item rather than somebody else's work.
             */
            ->where(static function (Builder $query) use ($visibleProjectIds): void {
                $query->where('bound.binding_count', '>', 0)
                    ->orWhereNotExists(static fn (Builder $inner): Builder => $inner
                        ->from('project_integration_bindings as any_b')
                        ->whereColumn('any_b.external_account_id', 'ea.id'));

                unset($visibleProjectIds);
            })
            ->select([
                'c.id as campaign_id',
                'c.name as campaign_name',
                'c.provider',
                'c.status',
                'c.project_id',
                'c.synced_at',
                'ea.id as external_account_id',
                'ea.name as external_account_name',
                'p.name as project_name',
                'cl.id as client_id',
                'cl.name as client_name',
                'bound.projects as candidate_projects',
                'bound.binding_count',
            ])
            ->selectRaw($this->ownershipExpression().' as ownership');
    }

    /**
     * The four states, as one SQL expression.
     *
     * Written once and reused by the page, the filter and the counts, because three copies of this rule
     * would be three chances for the number in the heading to disagree with the rows under it.
     */
    private function ownershipExpression(): string
    {
        return <<<'SQL'
            case
                when project_id is not null then 'assigned'
                when binding_count = 1 then 'implied'
                when binding_count = 0 then 'unbound'
                else 'ambiguous'
            end
            SQL;
    }

    private function toView(stdClass $row): CampaignOwnershipView
    {
        $assignedProject = $this->nullableString($row->project_id);
        /* The state SQL decided, not a second rule in PHP that could drift from it. */
        $ownership = CampaignOwnership::from((string) $row->ownership);

        /** @var list<array{id: string, name: string}> $candidates */
        $candidates = json_decode((string) ($row->candidate_projects ?? '[]'), true) ?: [];

        /*
         * An implied owner is a real answer, and leaving it blank because no row holds it would make the
         * common case look like the broken one.
         */
        $projectId = $assignedProject;
        $projectName = $this->nullableString($row->project_name);

        if ($ownership === CampaignOwnership::Implied && $candidates !== []) {
            $projectId = $candidates[0]['id'];
            $projectName = $candidates[0]['name'];
        }

        return new CampaignOwnershipView(
            campaignId: (string) $row->campaign_id,
            campaignName: (string) $row->campaign_name,
            provider: (string) $row->provider,
            externalAccountId: (string) $row->external_account_id,
            externalAccountName: (string) $row->external_account_name,
            status: (string) $row->status,
            ownership: $ownership,
            projectId: $projectId,
            projectName: $projectName,
            clientId: $this->nullableString($row->client_id),
            clientName: $this->nullableString($row->client_name),
            candidateProjects: $candidates,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
