<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Portfolio\Contract\PortfolioReadModel;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentPortfolioReadModel implements PortfolioReadModel
{
    private const string NOBODY = '00000000-0000-0000-0000-000000000000';

    /** @return array<string, array{leads: int, qualified: int}> */
    public function leads(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('leads as l')
            ->where('l.workspace_id', $workspaceId)
            ->whereBetween('l.received_at', [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')])
            ->when($visibleProjectIds !== null, fn (Builder $q): Builder => $q->whereIn('l.project_id', $visibleProjectIds ?: [self::NOBODY]))
            ->selectRaw('l.client_id, count(*) as leads')
            /* Qualified means reached the qualified stage or went on to win; a disqualified lead never counts. */
            ->selectRaw("count(*) filter (where l.stage in ('qualified','won')) as qualified")
            ->groupBy('l.client_id')
            ->get()->all();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r->client_id] = ['leads' => (int) $r->leads, 'qualified' => (int) $r->qualified];
        }

        return $out;
    }

    /** @return array<string, array{orders: int, revenue: array<string, int>}> */
    public function sales(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->where('o.workspace_id', $workspaceId)
            ->whereIn('o.payment_state', ['paid', 'partially_refunded'])
            ->whereBetween('o.placed_at', [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')])
            ->when($visibleProjectIds !== null, fn (Builder $q): Builder => $q->whereIn('s.client_id', DB::table('projects')
                ->select('client_id')->where('workspace_id', $workspaceId)->whereIn('id', $visibleProjectIds ?: [self::NOBODY])))
            ->selectRaw('s.client_id, o.currency, count(*) as orders, sum(o.total_minor - o.refunded_minor) as revenue')
            ->groupBy('s.client_id', 'o.currency')
            ->get()->all();

        $out = [];

        foreach ($rows as $r) {
            $client = (string) $r->client_id;
            $out[$client] ??= ['orders' => 0, 'revenue' => []];
            $out[$client]['orders'] += (int) $r->orders;
            $out[$client]['revenue'][(string) $r->currency] = (int) $r->revenue;
        }

        return $out;
    }

    /** @return array{clients: list<array{id: string, name: string, status: string}>, projects: list<array{id: string, client_id: string, name: string, status: string}>} */
    public function roster(string $workspaceId, ?array $visibleProjectIds, ?string $clientId): array
    {
        $projects = DB::table('projects')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
            ->when($visibleProjectIds !== null, fn (Builder $q): Builder => $q->whereIn('id', $visibleProjectIds ?: [self::NOBODY]))
            ->when($clientId !== null, fn (Builder $q): Builder => $q->where('client_id', $clientId))
            ->orderBy('name')->get(['id', 'client_id', 'name', 'status']);

        $clientIds = $projects->pluck('client_id')->unique()->values()->all();

        $clients = DB::table('clients')->where('workspace_id', $workspaceId)->whereNull('deleted_at')
            ->when($clientId !== null, fn (Builder $q): Builder => $q->where('id', $clientId))
            /* A scoped reader sees the clients of their projects, not the agency's whole client list. */
            ->when($visibleProjectIds !== null, fn (Builder $q): Builder => $q->whereIn('id', $clientIds ?: [self::NOBODY]))
            ->orderBy('name')->get(['id', 'name', 'status']);

        $outClients = [];

        foreach ($clients as $c) {
            $outClients[] = ['id' => (string) $c->id, 'name' => (string) $c->name, 'status' => (string) $c->status];
        }

        $outProjects = [];

        foreach ($projects as $p) {
            $outProjects[] = [
                'id' => (string) $p->id,
                'client_id' => (string) $p->client_id,
                'name' => (string) $p->name,
                'status' => (string) $p->status,
            ];
        }

        return ['clients' => $outClients, 'projects' => $outProjects];
    }

    /** @return array<string, int> */
    public function openWork(string $workspaceId, ?array $visibleProjectIds): array
    {
        $ids = $visibleProjectIds === null ? null : ($visibleProjectIds ?: [self::NOBODY]);

        $tasks = DB::table('tasks')->where('workspace_id', $workspaceId)->whereNotNull('project_id')
            ->whereNotIn('state', ['done', 'cancelled'])
            ->when($ids !== null, fn (Builder $q): Builder => $q->whereIn('project_id', $ids))
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->get();

        $requests = DB::table('client_requests')->where('workspace_id', $workspaceId)->whereNotNull('project_id')
            ->whereIn('state', ['submitted', 'accepted', 'in_progress'])
            ->when($ids !== null, fn (Builder $q): Builder => $q->whereIn('project_id', $ids))
            ->selectRaw('project_id, count(*) as n')->groupBy('project_id')->get();

        $out = [];

        foreach ([$tasks, $requests] as $set) {
            foreach ($set as $r) {
                $out[(string) $r->project_id] = ($out[(string) $r->project_id] ?? 0) + (int) $r->n;
            }
        }

        return $out;
    }
}
