<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Domain\Client\Client;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientProfile;
use App\Domain\Client\ClientStatus;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Identity\Email;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client as ClientModel;

/** Maps between the Client aggregate and its Eloquent row. */
final class EloquentClientRepository implements ClientRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ClientId $clientId): ?Client
    {
        $model = ClientModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->whereKey($clientId->value)
            ->first();

        return $model instanceof ClientModel ? $this->toDomain($model) : null;
    }

    public function existsWithSlug(WorkspaceId $workspaceId, Slug $slug, ?ClientId $excluding = null): bool
    {
        return ClientModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('slug', $slug->value)
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding?->value))
            ->exists();
    }

    public function save(Client $client): void
    {
        $profile = $client->profile();

        $model = ClientModel::query()->whereKey($client->id->value)->first() ?? new ClientModel;

        /*
         * forceFill, not fill: the identifier is assigned by the domain, and a
         * repository is a trusted mapper rather than a path for user input.
         * Mass-assignment protection still guards the HTTP boundary.
         */
        $model->forceFill([
            'id' => $client->id->value,
            'workspace_id' => $client->workspaceId->value,
            'name' => $client->name(),
            'slug' => $client->slug()->value,
            'status' => $client->status()->value,
            'legal_name' => $profile->legalName,
            'industry' => $profile->industry,
            'website' => $profile->website,
            'primary_contact_name' => $profile->primaryContactName,
            'primary_contact_email' => $profile->primaryContactEmail?->value,
            'primary_contact_phone' => $profile->primaryContactPhone,
            'default_currency' => $profile->defaultCurrency,
            'notes' => $profile->notes,
            'is_demo' => $client->isDemo,
        ])->save();
    }

    public function remove(Client $client): void
    {
        ClientModel::query()->whereKey($client->id->value)->delete();
    }

    private function toDomain(ClientModel $model): Client
    {
        /** @var string|null $email */
        $email = $model->getAttribute('primary_contact_email');

        return Client::reconstitute(
            id: new ClientId((string) $model->getKey()),
            workspaceId: new WorkspaceId((string) $model->getAttribute('workspace_id')),
            name: (string) $model->getAttribute('name'),
            slug: new Slug((string) $model->getAttribute('slug')),
            status: ClientStatus::from((string) $model->getAttribute('status')),
            profile: new ClientProfile(
                legalName: $this->nullableString($model->getAttribute('legal_name')),
                industry: $this->nullableString($model->getAttribute('industry')),
                website: $this->nullableString($model->getAttribute('website')),
                primaryContactName: $this->nullableString($model->getAttribute('primary_contact_name')),
                primaryContactEmail: $email === null || $email === '' ? null : new Email($email),
                primaryContactPhone: $this->nullableString($model->getAttribute('primary_contact_phone')),
                defaultCurrency: $this->nullableString($model->getAttribute('default_currency')),
                notes: $this->nullableString($model->getAttribute('notes')),
            ),
            isDemo: (bool) $model->getAttribute('is_demo'),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
