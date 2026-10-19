<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Search\Contract\SearchReadModel;
use App\Application\Search\DTO\SearchHit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Six entity types in one UNION ALL, each already narrowed to what the reader may see.
 *
 * Every branch is capped with its own LIMIT before the union, so the statement's cost is bounded by
 * `types × perType` rows whatever the size of the agency. Prefix matches rank above substring matches.
 */
final readonly class EloquentSearchReadModel implements SearchReadModel
{
    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $visibleProjectIds
     * @return list<SearchHit>
     */
    public function search(string $workspaceId, ?array $visibleProjectIds, array $types, string $term, int $perType): array
    {
        $escaped = addcslashes($term, '%_\\');
        $like = '%'.$escaped.'%';
        $prefix = $escaped.'%';
        $none = $visibleProjectIds === [];

        $branches = [];

        foreach ($types as $type) {
            $branch = match ($type) {
                'client' => $this->clients($workspaceId, $visibleProjectIds),
                'project' => $this->projects($workspaceId, $visibleProjectIds),
                'campaign' => $this->campaigns($workspaceId, $visibleProjectIds),
                'account' => $this->accounts($workspaceId, $visibleProjectIds),
                'lead' => $this->leads($workspaceId, $visibleProjectIds),
                'report' => $this->reports($workspaceId, $visibleProjectIds),
                default => null,
            };

            if ($branch === null || ($none && $type !== 'client' && $type !== 'report')) {
                continue;
            }

            $branches[] = $branch;
        }

        if ($branches === []) {
            return [];
        }

        /*
         * Each branch is wrapped in a subquery so the label alias exists when it is filtered and ranked,
         * and so its own ORDER BY/LIMIT survives the union — Postgres honours them only in parentheses.
         */
        $sql = [];
        $bindings = [];

        foreach ($branches as $branch) {
            $sql[] = '(select * from ('.$branch->toSql().') as hit where hit.label ilike ? '
                .'order by case when hit.label ilike ? then 0 else 1 end, hit.label limit '.$perType.')';
            $bindings = [...$bindings, ...$branch->getBindings(), $like, $prefix];
        }

        $sql = implode(' union all ', $sql);

        /** @var list<stdClass> $rows */
        $rows = DB::select($sql, $bindings);

        return array_map(static fn (stdClass $row): SearchHit => new SearchHit(
            type: (string) $row->type,
            id: (string) $row->id,
            label: (string) $row->label,
            context: is_string($row->context ?? null) && $row->context !== '' ? $row->context : null,
            path: (string) $row->path,
        ), $rows);
    }

    /**
     * @param  list<array{type: string, id: string}>  $entities
     * @param  list<string>|null  $visibleProjectIds
     * @return list<SearchHit>
     */
    public function lookup(string $workspaceId, ?array $visibleProjectIds, array $entities): array
    {
        $byType = [];

        foreach ($entities as $entity) {
            $byType[$entity['type']][] = $entity['id'];
        }

        $sql = [];
        $bindings = [];

        foreach ($byType as $type => $ids) {
            $branch = match ($type) {
                'client' => $this->clients($workspaceId, $visibleProjectIds),
                'project' => $this->projects($workspaceId, $visibleProjectIds),
                'campaign' => $this->campaigns($workspaceId, $visibleProjectIds),
                /* Looked up only — never offered in global search, where a thousand ads would drown every campaign. */
                'ad_group', 'ad' => $this->entities($type, $workspaceId, $visibleProjectIds),
                default => null,
            };

            if ($branch === null) {
                continue;
            }

            $sql[] = '(select * from ('.$branch->toSql().') as hit where hit.id in ('.implode(',', array_fill(0, count($ids), '?')).'))';
            $bindings = [...$bindings, ...$branch->getBindings(), ...$ids];
        }

        if ($sql === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = DB::select(implode(' union all ', $sql), $bindings);

        return array_map(static fn (stdClass $row): SearchHit => new SearchHit(
            type: (string) $row->type,
            id: (string) $row->id,
            label: (string) $row->label,
            context: is_string($row->context ?? null) && $row->context !== '' ? $row->context : null,
            path: (string) $row->path,
        ), $rows);
    }

    private function select(Builder $query, string $type, string $label, string $context, string $path): Builder
    {
        return $query->selectRaw(
            "'{$type}' as type, {$label} as label, {$context} as context, '{$path}' as path, id::text as id",
        );
    }

    /** @param list<string>|null $visible */
    private function clients(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('clients')->where('workspace_id', $workspaceId)->whereNull('deleted_at');

        if ($visible !== null) {
            $q->whereIn('id', DB::table('projects')->select('client_id')
                ->where('workspace_id', $workspaceId)->whereIn('id', $visible ?: ['00000000-0000-0000-0000-000000000000']));
        }

        return $this->select($q, 'client', 'name', 'industry', '/clients');
    }

    /** @param list<string>|null $visible */
    private function projects(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('projects as p')->join('clients as c', 'c.id', '=', 'p.client_id')
            ->where('p.workspace_id', $workspaceId)->whereNull('p.deleted_at');

        if ($visible !== null) {
            $q->whereIn('p.id', $visible);
        }

        return $q->selectRaw("'project' as type, p.name as label, c.name as context, '/projects' as path, p.id::text as id");
    }

    /** @param list<string>|null $visible */
    private function campaigns(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('campaigns as c')->join('external_accounts as a', 'a.id', '=', 'c.external_account_id')
            ->where('c.workspace_id', $workspaceId);

        $this->boundTo($q, 'a.id', $visible);

        return $q->selectRaw("'campaign' as type, c.name as label, a.name as context, '/campaigns' as path, c.id::text as id");
    }

    /** @param list<string>|null $visible */
    private function entities(string $type, string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table($type === 'ad' ? 'ads as e' : 'ad_groups as e')
            ->join('campaigns as c', 'c.id', '=', 'e.campaign_id')
            ->join('external_accounts as a', 'a.id', '=', 'e.external_account_id')
            ->where('e.workspace_id', $workspaceId);

        $this->boundTo($q, 'a.id', $visible);

        return $q->selectRaw("'{$type}' as type, e.name as label, c.name as context, '/campaigns' as path, e.id::text as id");
    }

    /** @param list<string>|null $visible */
    private function accounts(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('external_accounts as a')->where('a.workspace_id', $workspaceId);

        $this->boundTo($q, 'a.id', $visible);

        return $q->selectRaw("'account' as type, a.name as label, a.provider as context, '/integrations' as path, a.id::text as id");
    }

    /** @param list<string>|null $visible */
    private function leads(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('leads as l')->join('clients as c', 'c.id', '=', 'l.client_id')
            ->where('l.workspace_id', $workspaceId)->whereNotNull('l.name');

        if ($visible !== null) {
            $q->whereIn('l.project_id', $visible);
        }

        return $q->selectRaw("'lead' as type, l.name as label, c.name as context, '/leads' as path, l.id::text as id");
    }

    /** @param list<string>|null $visible */
    private function reports(string $workspaceId, ?array $visible): Builder
    {
        $q = DB::table('reports as r')->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->where('r.workspace_id', $workspaceId);

        if ($visible !== null) {
            $q->whereIn('r.client_id', DB::table('projects')->select('client_id')
                ->where('workspace_id', $workspaceId)->whereIn('id', $visible ?: ['00000000-0000-0000-0000-000000000000']));
        }

        return $q->selectRaw("'report' as type, r.name as label, c.name as context, '/reports' as path, r.id::text as id");
    }

    /**
     * Narrow to accounts bound to a project the reader may see. An unrestricted reader also sees
     * accounts bound to nothing — they are the review queue — while a scoped one never does.
     *
     * @param  list<string>|null  $visible
     */
    private function boundTo(Builder $q, string $accountColumn, ?array $visible): void
    {
        if ($visible === null) {
            return;
        }

        $q->whereIn($accountColumn, DB::table('project_integration_bindings')
            ->select('external_account_id')
            ->whereIn('project_id', $visible ?: ['00000000-0000-0000-0000-000000000000']));
    }
}
