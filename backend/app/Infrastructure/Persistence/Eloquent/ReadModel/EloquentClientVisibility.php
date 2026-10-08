<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Client\Contract\ClientVisibility;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one SQL expression of "may this reader see this client".
 *
 * A client is visible when it owns a project the reader can see. The narrowing is a subquery on `projects` rather
 * than a join, so a client with four visible projects is one row rather than four — which matters because the
 * other method on this contract returns a list somebody renders.
 */
final readonly class EloquentClientVisibility implements ClientVisibility
{
    public function isVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool
    {
        if ($visibleProjectIds === []) {
            return false;
        }

        $builder = DB::table('clients')
            ->where('workspace_id', $workspaceId)
            ->where('id', $clientId);

        if ($visibleProjectIds !== null) {
            $this->narrow($builder, $workspaceId, $visibleProjectIds, 'clients.id');
        }

        return $builder->exists();
    }

    /** @return array<string, string> */
    public function visible(string $workspaceId, ?array $visibleProjectIds): array
    {
        if ($visibleProjectIds === []) {
            return [];
        }

        $builder = DB::table('clients')
            ->where('workspace_id', $workspaceId)
            ->orderBy('name');

        if ($visibleProjectIds !== null) {
            $this->narrow($builder, $workspaceId, $visibleProjectIds, 'clients.id');
        }

        $clients = [];

        foreach ($builder->get(['id', 'name']) as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $clients[(string) ($record['id'] ?? '')] = (string) ($record['name'] ?? '');
        }

        return $clients;
    }

    /** @param  list<string>  $visibleProjectIds */
    private function narrow(Builder $builder, string $workspaceId, array $visibleProjectIds, string $column): void
    {
        $builder->whereIn($column, function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
            $sub->select('client_id')
                ->from('projects')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $visibleProjectIds);
        });
    }
}
