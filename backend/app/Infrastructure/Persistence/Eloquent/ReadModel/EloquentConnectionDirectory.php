<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Integration\DTO\AccountRow;
use App\Application\Integration\DTO\ConnectionView;
use App\Domain\Integration\ConnectionBusinessState;
use App\Domain\Integration\ConnectionOwnerType;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentConnectionDirectory implements ConnectionDirectory
{
    /** @return list<ConnectionView> */
    public function connections(string $workspaceId, bool $withTechnicalDetail): array
    {
        /*
         * Counts by correlated aggregate, one statement for every connection. "Bound" is an account with
         * any binding; "unassigned" excludes the ones an operator deliberately ignored.
         */
        /** @var list<stdClass> $rows */
        $rows = DB::table('provider_connections as pc')
            ->leftJoin('clients as cl', 'cl.id', '=', 'pc.owner_client_id')
            ->where('pc.workspace_id', $workspaceId)
            ->whereNull('pc.deleted_at')
            ->selectRaw('pc.*, cl.name as client_name')
            ->selectRaw('(select count(*) from external_accounts a where a.provider_connection_id = pc.id) as discovered')
            ->selectRaw('(select count(*) from external_accounts a where a.provider_connection_id = pc.id
                and exists (select 1 from project_integration_bindings b where b.external_account_id = a.id)) as bound')
            ->selectRaw('(select count(*) from external_accounts a where a.provider_connection_id = pc.id
                and a.ignored_at is null
                and not exists (select 1 from project_integration_bindings b where b.external_account_id = a.id)) as unassigned')
            ->orderByRaw("case when pc.owner_type = 'agency' then 0 else 1 end")
            ->orderBy('pc.provider')
            ->orderBy('pc.name')
            ->get()
            ->all();

        return array_map(fn (stdClass $r): ConnectionView => $this->toConnection($r, $withTechnicalDetail), $rows);
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<string>  $states
     * @return list<AccountRow>
     */
    public function accounts(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $states,
        ?string $connectionId,
        int $limit,
        int $offset,
    ): array {
        $query = DB::query()->fromSub($this->accountBase($workspaceId, $visibleProjectIds), 'acc');

        if ($states !== []) {
            $query->whereIn('state', $states);
        }

        if ($connectionId !== null) {
            $query->where('connection_id', $connectionId);
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderBy('provider')->orderBy('name')->orderBy('id')->limit($limit)->offset($offset)->get()->all();

        if ($rows === []) {
            return [];
        }

        /* The project lists for the whole page in one statement, not one per account. */
        $ids = array_map(static fn (stdClass $r): string => (string) $r->id, $rows);
        $byAccount = [];

        /** @var list<stdClass> $bound */
        $bound = DB::table('project_integration_bindings as b')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->join('clients as c', 'c.id', '=', 'p.client_id')
            ->whereIn('b.external_account_id', $ids)
            ->when($visibleProjectIds !== null, static fn (Builder $q): Builder => $q->whereIn('p.id', $visibleProjectIds ?: ['00000000-0000-0000-0000-000000000000']))
            ->orderBy('p.name')
            ->get(['b.external_account_id', 'p.id', 'p.name', 'c.name as client_name'])
            ->all();

        foreach ($bound as $b) {
            $byAccount[(string) $b->external_account_id][] = [
                'id' => (string) $b->id,
                'name' => (string) $b->name,
                'client_name' => (string) $b->client_name,
            ];
        }

        return array_map(static fn (stdClass $r): AccountRow => new AccountRow(
            id: (string) $r->id,
            name: (string) $r->name,
            provider: (string) $r->provider,
            externalId: (string) $r->external_id,
            connectionId: (string) $r->connection_id,
            connectionLabel: (string) $r->connection_name,
            ownerClientId: is_string($r->owner_client_id) ? $r->owner_client_id : null,
            ownerClientName: is_string($r->owner_client_name) ? $r->owner_client_name : null,
            currency: is_string($r->currency) ? $r->currency : null,
            status: (string) $r->status,
            state: (string) $r->state,
            projects: $byAccount[(string) $r->id] ?? [],
            campaigns: (int) $r->campaigns,
        ), $rows);
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int>
     */
    public function accountCounts(string $workspaceId, ?array $visibleProjectIds): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::query()
            ->fromSub($this->accountBase($workspaceId, $visibleProjectIds), 'acc')
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->get()
            ->all();

        $counts = ['unassigned' => 0, 'bound' => 0, 'shared' => 0, 'ignored' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row->state] = (int) $row->total;
        }

        return $counts;
    }

    public function ignore(string $workspaceId, string $accountId, string $userId, DateTimeImmutable $at): bool
    {
        return DB::table('external_accounts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $accountId)
            /* Only the unassigned. Ignoring a bound account would silently strand a project's data. */
            ->whereNotExists(static fn (Builder $q): Builder => $q->from('project_integration_bindings as b')
                ->whereColumn('b.external_account_id', 'external_accounts.id'))
            ->update(['ignored_at' => $at, 'ignored_by' => $userId]) > 0;
    }

    public function restore(string $workspaceId, string $accountId): bool
    {
        return DB::table('external_accounts')
            ->where('workspace_id', $workspaceId)
            ->where('id', $accountId)
            ->update(['ignored_at' => null, 'ignored_by' => null]) > 0;
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     */
    private function accountBase(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = DB::table('external_accounts as a')
            ->join('provider_connections as pc', 'pc.id', '=', 'a.provider_connection_id')
            ->leftJoin('clients as oc', 'oc.id', '=', 'pc.owner_client_id')
            ->where('a.workspace_id', $workspaceId)
            ->selectRaw('a.id, a.name, a.provider, a.external_id, a.currency, a.status, a.provider_connection_id as connection_id')
            ->selectRaw('pc.name as connection_name, pc.owner_client_id, oc.name as owner_client_name')
            ->selectRaw('(select count(*) from campaigns c where c.external_account_id = a.id) as campaigns')
            ->selectRaw("case
                when exists (select 1 from project_integration_bindings b where b.external_account_id = a.id)
                    then case when (select count(*) from project_integration_bindings b where b.external_account_id = a.id) > 1
                        then 'shared' else 'bound' end
                when a.ignored_at is not null then 'ignored'
                else 'unassigned' end as state");

        if ($visibleProjectIds !== null) {
            /* A scoped reader sees accounts bound to their projects, and never the unassigned queue. */
            $query->whereExists(static fn (Builder $q): Builder => $q->from('project_integration_bindings as vb')
                ->whereColumn('vb.external_account_id', 'a.id')
                ->whereIn('vb.project_id', $visibleProjectIds ?: ['00000000-0000-0000-0000-000000000000']));
        }

        return $query;
    }

    private function toConnection(stdClass $r, bool $withTechnicalDetail): ConnectionView
    {
        $provider = Provider::from((string) $r->provider);
        $status = ConnectionStatus::from((string) $r->status);
        $owner = ConnectionOwnerType::from((string) $r->owner_type);
        $clientName = is_string($r->client_name) ? $r->client_name : null;
        $owned = $owner === ConnectionOwnerType::Agency ? 'Smart Code' : ($clientName ?? 'Client');

        return new ConnectionView(
            id: (string) $r->id,
            provider: $provider->value,
            providerLabel: $provider->label(),
            label: str_contains((string) $r->name, $owned)
                ? (string) $r->name
                : sprintf('%s — %s', (string) $r->name, $owned),
            ownerType: $owner,
            ownerClientId: is_string($r->owner_client_id) ? $r->owner_client_id : null,
            ownerClientName: $clientName,
            status: $status,
            state: ConnectionBusinessState::fromStatus($status),
            accountsDiscovered: (int) $r->discovered,
            accountsBound: (int) $r->bound,
            accountsUnassigned: (int) $r->unassigned,
            lastCheckedAt: is_string($r->last_checked_at) ? new DateTimeImmutable($r->last_checked_at) : null,
            expiresAt: is_string($r->expires_at) ? new DateTimeImmutable($r->expires_at) : null,
            technicalMessage: $withTechnicalDetail && is_string($r->status_message) ? $r->status_message : null,
        );
    }
}
