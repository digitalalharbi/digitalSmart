<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Application\Platform\Contract\PlatformReadModel;
use App\Application\Platform\DTO\PlatformWorkspaceRow;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Saas\EloquentUsageReader;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Tenants as counts. One query for the page of workspaces and one grouped query per kind of count, so the cost does not grow with
 * the number of workspaces on a page. Nothing here reads a client's name, a figure, a message or a credential.
 */
final readonly class EloquentPlatformReadModel implements PlatformReadModel
{
    private const array HISTORY = [
        AuditAction::WorkspaceCreated,
        AuditAction::WorkspacePlanChanged,
        AuditAction::WorkspaceEntitlementOverridden,
        AuditAction::WorkspaceSubscriptionChanged,
    ];

    public function workspaces(?string $search, ?string $status, int $page, int $perPage): array
    {
        $query = $this->base();

        if ($search !== null && $search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';
            $query->where(static fn (Builder $q) => $q->whereRaw('lower(w.name) like ?', [$like])->orWhereRaw('lower(w.slug) like ?', [$like]));
        }

        if ($status !== null && $status !== '') {
            $query->whereRaw("coalesce(s.status, 'active') = ?", [$status]);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('w.name')->orderBy('w.id')->forPage($page, $perPage)->get()->all();

        return ['rows' => $this->hydrate($rows), 'total' => $total];
    }

    public function find(string $workspaceId): ?PlatformWorkspaceRow
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $workspaceId) !== 1) {
            return null;
        }

        return $this->hydrate($this->base()->where('w.id', $workspaceId)->get()->all())[0] ?? null;
    }

    public function slugTaken(string $slug): bool
    {
        return DB::table('workspaces')->where('slug', $slug)->exists();
    }

    public function history(string $workspaceId, int $limit = 30): array
    {
        return array_values(DB::table('audit_logs')
            ->where('workspace_id', $workspaceId)
            ->whereIn('action', array_map(static fn (AuditAction $a): string => $a->value, self::HISTORY))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['action', 'summary', 'created_at'])
            ->map(static fn (stdClass $row): array => [
                'action' => (string) $row->action,
                'summary' => $row->summary === null ? null : (string) $row->summary,
                'at' => (new DateTimeImmutable((string) $row->created_at))->format(DATE_ATOM),
            ])->all());
    }

    private function base(): Builder
    {
        return DB::table('workspaces as w')
            ->leftJoin('workspace_subscriptions as s', 's.workspace_id', '=', 'w.id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id')
            ->select([
                'w.id', 'w.name', 'w.slug', 'w.type', 'w.created_at',
                'p.code as plan_code', 's.workspace_id as subscribed',
                DB::raw("coalesce(s.status, 'active') as status"),
                's.trial_ends_at', 's.grace_ends_at', 's.status_reason',
            ]);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PlatformWorkspaceRow>
     */
    private function hydrate(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn (mixed $r): bool => $r instanceof stdClass));
        $ids = array_map(static fn (stdClass $r): string => (string) $r->id, $rows);

        if ($ids === []) {
            return [];
        }

        $seats = [];
        foreach (DB::table('workspace_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.workspace_id', $ids)->whereIn('m.status', ['active', 'invited'])
            ->where('u.email', 'not like', EloquentUsageReader::AGENT_EMAILS)
            ->groupBy('m.workspace_id', 'm.seat_type')
            ->get(['m.workspace_id', 'm.seat_type', DB::raw('count(*) as n')]) as $row) {
            $seats[(string) $row->workspace_id][(string) $row->seat_type] = (int) $row->n;
        }

        $owners = DB::table('workspace_members as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->whereIn('m.workspace_id', $ids)->where('m.status', 'active')->where('r.slug', SystemRole::Owner->value)
            ->groupBy('m.workspace_id')->selectRaw('m.workspace_id, count(*) as n')->pluck('n', 'workspace_id')->all();
        $roles = DB::table('roles')->whereIn('workspace_id', $ids)->where('is_system', true)
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')->pluck('n', 'workspace_id')->all();
        $clients = DB::table('clients')->whereIn('workspace_id', $ids)->whereNull('deleted_at')
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')->pluck('n', 'workspace_id')->all();
        $projects = DB::table('projects')->whereIn('workspace_id', $ids)->whereNull('deleted_at')
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')->pluck('n', 'workspace_id')->all();
        $overrides = DB::table('workspace_entitlement_overrides')->whereIn('workspace_id', $ids)
            ->groupBy('workspace_id')->selectRaw('workspace_id, count(*) as n')->pluck('n', 'workspace_id')->all();

        $connections = [];
        foreach (DB::table('provider_connections')->whereIn('workspace_id', $ids)->groupBy('workspace_id', 'status')
            ->get(['workspace_id', 'status', DB::raw('count(*) as n')]) as $row) {
            $connections[(string) $row->workspace_id][(string) $row->status] = (int) $row->n;
        }

        $systemRoles = count(SystemRole::cases());
        $date = static fn (mixed $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable((string) $v);

        return array_map(static function (stdClass $r) use ($seats, $owners, $roles, $clients, $projects, $overrides, $connections, $systemRoles, $date): PlatformWorkspaceRow {
            $id = (string) $r->id;
            $s = $seats[$id] ?? [];
            $missing = [];

            if ((int) ($owners[$id] ?? 0) === 0) {
                $missing[] = 'owner';
            }

            if ((int) ($roles[$id] ?? 0) < $systemRoles) {
                $missing[] = 'roles';
            }

            if ($r->subscribed === null) {
                $missing[] = 'subscription';
            }

            return new PlatformWorkspaceRow(
                id: $id,
                name: (string) $r->name,
                slug: (string) $r->slug,
                type: (string) $r->type,
                createdAt: new DateTimeImmutable((string) $r->created_at),
                planCode: $r->plan_code === null ? 'none' : (string) $r->plan_code,
                status: (string) $r->status,
                trialEndsAt: $date($r->trial_ends_at),
                graceEndsAt: $date($r->grace_ends_at),
                statusReason: $r->status_reason === null ? null : (string) $r->status_reason,
                owners: (int) ($owners[$id] ?? 0),
                internalSeats: ($s['full'] ?? 0) + ($s['limited'] ?? 0),
                clientSeats: ($s['client'] ?? 0) + ($s['guest'] ?? 0),
                clients: (int) ($clients[$id] ?? 0),
                projects: (int) ($projects[$id] ?? 0),
                connections: $connections[$id] ?? [],
                overrides: (int) ($overrides[$id] ?? 0),
                missing: $missing,
            );
        }, $rows);
    }
}
