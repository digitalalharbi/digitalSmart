<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Integration\Contract\CredentialCipher;
use App\Domain\Client\ClientId;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\ConnectionOwnerType;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\ProviderConnection as ConnectionModel;
use DateTimeImmutable;
use DateTimeInterface;

/** Encrypts on the way in, decrypts on the way out. Nothing else sees the blob. */
final readonly class EloquentProviderConnectionRepository implements ProviderConnectionRepository
{
    public function __construct(private CredentialCipher $cipher) {}

    public function findInWorkspace(WorkspaceId $workspaceId, ProviderConnectionId $id): ?ProviderConnection
    {
        $model = ConnectionModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->whereKey($id->value)
            ->first();

        return $model instanceof ConnectionModel ? $this->toDomain($model) : null;
    }

    public function agencyConnection(WorkspaceId $workspaceId, Provider $provider): ?ProviderConnection
    {
        $model = ConnectionModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('provider', $provider->value)
            ->where('owner_type', ConnectionOwnerType::Agency->value)
            ->first();

        return $model instanceof ConnectionModel ? $this->toDomain($model) : null;
    }

    public function forClient(
        WorkspaceId $workspaceId,
        Provider $provider,
        ClientId $clientId,
    ): ?ProviderConnection {
        $model = ConnectionModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('provider', $provider->value)
            ->where('owner_type', ConnectionOwnerType::Client->value)
            ->where('owner_client_id', $clientId->value)
            ->first();

        return $model instanceof ConnectionModel ? $this->toDomain($model) : null;
    }

    /** @return list<ProviderConnection> */
    public function listForProvider(WorkspaceId $workspaceId, Provider $provider): array
    {
        return $this->ordered(
            ConnectionModel::query()
                ->where('workspace_id', $workspaceId->value)
                ->where('provider', $provider->value),
        );
    }

    /** @return list<ProviderConnection> */
    public function listInWorkspace(WorkspaceId $workspaceId): array
    {
        return $this->ordered(
            ConnectionModel::query()->where('workspace_id', $workspaceId->value)->orderBy('provider'),
        );
    }

    /** @return list<WorkspaceId> */
    public function workspacesWith(Provider $provider): array
    {
        /** @var list<WorkspaceId> $ids */
        $ids = ConnectionModel::query()
            ->where('provider', $provider->value)
            ->orderBy('created_at')
            ->pluck('workspace_id')
            ->map(static fn (mixed $id): WorkspaceId => new WorkspaceId((string) $id))
            ->values()
            ->all();

        return $ids;
    }

    public function save(ProviderConnection $connection): void
    {
        $model = ConnectionModel::query()->whereKey($connection->id->value)->first() ?? new ConnectionModel;

        $credentials = $connection->credentials();

        $model->forceFill([
            'id' => $connection->id->value,
            'workspace_id' => $connection->workspaceId->value,
            'provider' => $connection->provider->value,
            'owner_type' => $connection->owner->type->value,
            'owner_client_id' => $connection->owner->clientId?->value,
            'name' => $connection->name(),
            'status' => $connection->status()->value,
            // Empty credentials are stored as null rather than an encrypted
            // empty array, so "no credentials" is unambiguous in the database.
            'credentials' => $credentials->isEmpty() ? null : $this->cipher->encrypt($credentials),
            'external_owner_id' => $connection->externalAccountOwnerId(),
            'expires_at' => $connection->expiresAt()?->format(DateTimeInterface::ATOM),
            'last_checked_at' => $connection->lastCheckedAt()?->format(DateTimeInterface::ATOM),
            'status_message' => $connection->statusMessage(),
        ])->save();
    }

    public function remove(ProviderConnection $connection): void
    {
        ConnectionModel::query()->whereKey($connection->id->value)->delete();
    }

    private function toDomain(ConnectionModel $model): ProviderConnection
    {
        /** @var string|null $credentials */
        $credentials = $model->getAttribute('credentials');

        return ProviderConnection::reconstitute(
            id: new ProviderConnectionId((string) $model->getKey()),
            workspaceId: new WorkspaceId((string) $model->getAttribute('workspace_id')),
            provider: Provider::from((string) $model->getAttribute('provider')),
            owner: $this->toOwner($model),
            name: (string) $model->getAttribute('name'),
            status: ConnectionStatus::from((string) $model->getAttribute('status')),
            credentials: $this->cipher->decrypt($credentials),
            externalAccountOwnerId: $this->nullableString($model->getAttribute('external_owner_id')),
            expiresAt: $this->toDate($model->getAttribute('expires_at')),
            lastCheckedAt: $this->toDate($model->getAttribute('last_checked_at')),
            statusMessage: $this->nullableString($model->getAttribute('status_message')),
        );
    }

    private function toOwner(ConnectionModel $model): ConnectionOwner
    {
        $clientId = $this->nullableString($model->getAttribute('owner_client_id'));

        return ConnectionOwner::reconstitute(
            ConnectionOwnerType::from((string) $model->getAttribute('owner_type')),
            $clientId === null ? null : new ClientId($clientId),
        );
    }

    /**
     * Agency-owned first, then by name.
     *
     * The order is part of the contract: a caller choosing without further information should land on the
     * agency's own credentials, and `ORDER BY` nothing would make that choice depend on physical row order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ConnectionModel>  $query
     * @return list<ProviderConnection>
     */
    private function ordered(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $connections = $query
            ->orderByRaw("case when owner_type = 'agency' then 0 else 1 end")
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (ConnectionModel $model): ProviderConnection => $this->toDomain($model))
            ->values()
            ->all();

        /** @var list<ProviderConnection> $connections */
        return $connections;
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

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
