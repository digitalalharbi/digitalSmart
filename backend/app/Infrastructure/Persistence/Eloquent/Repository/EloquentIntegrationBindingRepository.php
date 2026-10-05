<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\IntegrationBindingId;
use App\Domain\Integration\ProjectIntegrationBinding;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectIntegrationBinding as BindingModel;
use DateTimeImmutable;
use DateTimeInterface;

final class EloquentIntegrationBindingRepository implements IntegrationBindingRepository
{
    public function find(WorkspaceId $workspaceId, IntegrationBindingId $id): ?ProjectIntegrationBinding
    {
        $model = BindingModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->whereKey($id->value)
            ->first();

        return $model instanceof BindingModel ? $this->toDomain($model) : null;
    }

    public function findForPair(
        WorkspaceId $workspaceId,
        ProjectId $projectId,
        ExternalAccountId $externalAccountId,
    ): ?ProjectIntegrationBinding {
        $model = BindingModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('project_id', $projectId->value)
            ->where('external_account_id', $externalAccountId->value)
            ->first();

        return $model instanceof BindingModel ? $this->toDomain($model) : null;
    }

    public function save(ProjectIntegrationBinding $binding): void
    {
        $model = BindingModel::query()->whereKey($binding->id->value)->first() ?? new BindingModel;

        $model->forceFill([
            'id' => $binding->id->value,
            'workspace_id' => $binding->workspaceId->value,
            'project_id' => $binding->projectId->value,
            'external_account_id' => $binding->externalAccountId->value,
            'sync_enabled' => $binding->syncEnabled(),
            'bound_at' => $binding->boundAt->format(DateTimeInterface::ATOM),
            'last_synced_at' => $binding->lastSyncedAt()?->format(DateTimeInterface::ATOM),
        ])->save();
    }

    public function remove(ProjectIntegrationBinding $binding): void
    {
        BindingModel::query()->whereKey($binding->id->value)->delete();
    }

    public function countForAccount(WorkspaceId $workspaceId, ExternalAccountId $externalAccountId): int
    {
        return BindingModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('external_account_id', $externalAccountId->value)
            ->count();
    }

    private function toDomain(BindingModel $model): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::reconstitute(
            id: new IntegrationBindingId((string) $model->getKey()),
            workspaceId: new WorkspaceId((string) $model->getAttribute('workspace_id')),
            projectId: new ProjectId((string) $model->getAttribute('project_id')),
            externalAccountId: new ExternalAccountId((string) $model->getAttribute('external_account_id')),
            syncEnabled: (bool) $model->getAttribute('sync_enabled'),
            boundAt: $this->toDate($model->getAttribute('bound_at')) ?? new DateTimeImmutable,
            lastSyncedAt: $this->toDate($model->getAttribute('last_synced_at')),
        );
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
