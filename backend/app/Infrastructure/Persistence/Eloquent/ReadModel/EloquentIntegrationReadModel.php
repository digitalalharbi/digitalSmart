<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\DTO\BindingView;
use App\Application\Integration\DTO\ExternalAccountView;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\ExternalAccountStatus;
use App\Domain\Integration\Provider;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentIntegrationReadModel implements IntegrationReadModel
{
    /** @return list<ExternalAccountView> */
    public function accountsForWorkspace(WorkspaceId $workspaceId, ?Provider $provider): array
    {
        $query = DB::table('external_accounts')
            ->select([
                'external_accounts.id', 'external_accounts.provider', 'external_accounts.external_id',
                'external_accounts.name', 'external_accounts.currency', 'external_accounts.timezone',
                'external_accounts.status', 'external_accounts.discovered_at',
            ])
            // One correlated subquery beats loading relations: the page shows
            // "used by N projects" and nothing more.
            ->selectSub(
                DB::table('project_integration_bindings')
                    ->selectRaw('count(*)')
                    ->whereColumn('project_integration_bindings.external_account_id', 'external_accounts.id'),
                'binding_count',
            )
            ->where('external_accounts.workspace_id', $workspaceId->value);

        if ($provider instanceof Provider) {
            $query->where('external_accounts.provider', $provider->value);
        }

        /** @var list<ExternalAccountView> $views */
        $views = $query
            ->orderBy('external_accounts.provider')
            ->orderBy('external_accounts.name')
            ->get()
            ->map(function (stdClass $row): ExternalAccountView {
                $provider = Provider::from((string) $row->provider);
                $status = ExternalAccountStatus::from((string) $row->status);

                return new ExternalAccountView(
                    id: (string) $row->id,
                    provider: $provider->value,
                    providerLabel: $provider->label(),
                    externalId: (string) $row->external_id,
                    name: (string) $row->name,
                    currency: $this->nullableString($row->currency),
                    timezone: $this->nullableString($row->timezone),
                    status: $status->value,
                    isBindable: $status->isUsable(),
                    bindingCount: (int) $row->binding_count,
                    discoveredAt: $this->toIso($row->discovered_at),
                );
            })
            ->values()
            ->all();

        return $views;
    }

    /** @return list<BindingView> */
    public function bindingsForProject(WorkspaceId $workspaceId, ProjectId $projectId): array
    {
        /** @var list<BindingView> $views */
        $views = DB::table('project_integration_bindings as bindings')
            ->join('projects', 'projects.id', '=', 'bindings.project_id')
            ->join('external_accounts', 'external_accounts.id', '=', 'bindings.external_account_id')
            ->select([
                'bindings.id', 'bindings.project_id', 'bindings.external_account_id',
                'bindings.sync_enabled', 'bindings.bound_at', 'bindings.last_synced_at',
                'projects.name as project_name',
                'external_accounts.name as account_name', 'external_accounts.provider',
            ])
            ->where('bindings.workspace_id', $workspaceId->value)
            ->where('bindings.project_id', $projectId->value)
            ->orderBy('external_accounts.provider')
            ->orderBy('external_accounts.name')
            ->get()
            ->map(function (stdClass $row): BindingView {
                $provider = Provider::from((string) $row->provider);

                return new BindingView(
                    id: (string) $row->id,
                    projectId: (string) $row->project_id,
                    projectName: (string) $row->project_name,
                    externalAccountId: (string) $row->external_account_id,
                    externalAccountName: (string) $row->account_name,
                    provider: $provider->value,
                    providerLabel: $provider->label(),
                    syncEnabled: (bool) $row->sync_enabled,
                    boundAt: $this->toIso($row->bound_at),
                    lastSyncedAt: $this->toIso($row->last_synced_at),
                );
            })
            ->values()
            ->all();

        return $views;
    }

    /** @return array<string, int> */
    public function bindingCounts(WorkspaceId $workspaceId): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('project_integration_bindings')
            ->selectRaw('external_account_id, count(*) as total')
            ->where('workspace_id', $workspaceId->value)
            ->groupBy('external_account_id')
            ->pluck('total', 'external_account_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    public function accountExists(WorkspaceId $workspaceId, ExternalAccountId $id): bool
    {
        return DB::table('external_accounts')
            ->where('workspace_id', $workspaceId->value)
            ->where('id', $id->value)
            ->exists();
    }

    /** @return array<string, int> */
    public function accountCountsByProvider(WorkspaceId $workspaceId): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('external_accounts')
            ->selectRaw('provider, count(*) as total')
            ->where('workspace_id', $workspaceId->value)
            ->groupBy('provider')
            ->pluck('total', 'provider')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }
}
