<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Domain\Integration\ExternalAccount;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\ExternalAccountStatus;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\ExternalAccount as AccountModel;

final class EloquentExternalAccountRepository implements ExternalAccountRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ExternalAccountId $id): ?ExternalAccount
    {
        $model = AccountModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->whereKey($id->value)
            ->first();

        return $model instanceof AccountModel ? $this->toDomain($model) : null;
    }

    public function findByExternalId(
        WorkspaceId $workspaceId,
        ProviderConnectionId $connectionId,
        string $externalId,
    ): ?ExternalAccount {
        $model = AccountModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('provider_connection_id', $connectionId->value)
            ->where('external_id', $externalId)
            ->first();

        return $model instanceof AccountModel ? $this->toDomain($model) : null;
    }

    public function save(ExternalAccount $account): void
    {
        $model = AccountModel::query()->whereKey($account->id->value)->first() ?? new AccountModel;

        $model->forceFill([
            'id' => $account->id->value,
            'workspace_id' => $account->workspaceId->value,
            'provider_connection_id' => $account->connectionId->value,
            'provider' => $account->provider->value,
            'external_id' => $account->externalId,
            'name' => $account->name(),
            'currency' => $account->currency(),
            'timezone' => $account->timezone(),
            'status' => $account->status()->value,
            'discovered_at' => $model->getAttribute('discovered_at') ?? now(),
        ])->save();
    }

    /** @return list<ExternalAccount> */
    public function forConnection(WorkspaceId $workspaceId, ProviderConnectionId $connectionId): array
    {
        /** @var list<ExternalAccount> $accounts */
        $accounts = AccountModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('provider_connection_id', $connectionId->value)
            ->orderBy('name')
            ->get()
            ->map(fn (AccountModel $model): ExternalAccount => $this->toDomain($model))
            ->values()
            ->all();

        return $accounts;
    }

    private function toDomain(AccountModel $model): ExternalAccount
    {
        return ExternalAccount::reconstitute(
            id: new ExternalAccountId((string) $model->getKey()),
            workspaceId: new WorkspaceId((string) $model->getAttribute('workspace_id')),
            connectionId: new ProviderConnectionId((string) $model->getAttribute('provider_connection_id')),
            provider: Provider::from((string) $model->getAttribute('provider')),
            externalId: (string) $model->getAttribute('external_id'),
            name: (string) $model->getAttribute('name'),
            currency: $this->nullableString($model->getAttribute('currency')),
            timezone: $this->nullableString($model->getAttribute('timezone')),
            status: ExternalAccountStatus::from((string) $model->getAttribute('status')),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
