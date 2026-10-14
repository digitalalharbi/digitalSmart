<?php

declare(strict_types=1);

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Client\ClientId;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\ConnectionOwnerType;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Client as ClientModel;
use Illuminate\Database\QueryException;
use Tests\Support\WorkspaceScenario;

/**
 * Whose credentials these are.
 *
 * The table used to be unique on `(workspace_id, provider)`, which made one agency scenario impossible to
 * record: the agency runs its own manager account, and separately holds Meta credentials a client granted
 * directly because they will not move their ad account under the agency's Business Manager. Both are in use
 * on the same platform on the same day, and before this one of them had to be left out of the product.
 */
function connection(
    WorkspaceScenario $scenario,
    Provider $provider,
    ConnectionOwner $owner,
    string $name = 'Connection',
): ProviderConnection {
    return ProviderConnection::register(
        id: new ProviderConnectionId(app(IdentifierGenerator::class)->generate()),
        workspaceId: new WorkspaceId((string) $scenario->workspace->getKey()),
        provider: $provider,
        owner: $owner,
        name: $name,
        credentials: new Credentials([
            'client_id' => Credentials::SANDBOX_TOKEN,
            'client_secret' => Credentials::SANDBOX_TOKEN,
        ]),
    );
}

function clientIn(WorkspaceScenario $scenario): ClientId
{
    $client = ClientModel::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);

    return new ClientId((string) $client->getKey());
}

it('holds the agency connection and a client-granted one to the same platform at once', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());
    $clientId = clientIn($scenario);

    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::agency(), 'Agency Business Manager'));
    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::client($clientId), 'Alhambra Business Manager'));

    expect($repository->listForProvider($workspaceId, Provider::Meta))->toHaveCount(2);
});

/*
 * The old constraint's argument survives in a narrower form. Two sets of the agency's own credentials for
 * one platform is not a scenario — it is somebody configuring the same thing twice — and the second one
 * would silently decide which token a sync spends.
 */
it('still refuses a second agency connection to the same platform', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);

    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::agency(), 'First'));

    expect(fn () => $repository->save(
        connection($scenario, Provider::Meta, ConnectionOwner::agency(), 'Second'),
    ))->toThrow(QueryException::class);
});

it('refuses two connections granted by the same client for the same platform', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $clientId = clientIn($scenario);

    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::client($clientId), 'First'));

    expect(fn () => $repository->save(
        connection($scenario, Provider::Meta, ConnectionOwner::client($clientId), 'Second'),
    ))->toThrow(QueryException::class);
});

it('allows two different clients to grant their own connection to one platform', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());

    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::client(clientIn($scenario)), 'Alhambra'));
    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::client(clientIn($scenario)), 'Najd'));

    expect($repository->listForProvider($workspaceId, Provider::Meta))->toHaveCount(2);
});

/*
 * The hazard the whole change introduces: a lookup by provider alone, once more than one connection exists,
 * can read a client's data with the agency's token or the reverse. `agencyConnection` is narrow on purpose.
 */
it('never returns a client-granted connection as the agency one', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());
    $clientId = clientIn($scenario);

    $repository->save(connection($scenario, Provider::TikTok, ConnectionOwner::client($clientId), 'Client TikTok'));

    expect($repository->agencyConnection($workspaceId, Provider::TikTok))->toBeNull()
        ->and($repository->forClient($workspaceId, Provider::TikTok, $clientId))->not->toBeNull();
});

it('does not hand one client the connection another client granted', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());
    $granted = clientIn($scenario);
    $other = clientIn($scenario);

    $repository->save(connection($scenario, Provider::Snapchat, ConnectionOwner::client($granted), 'Theirs'));

    expect($repository->forClient($workspaceId, Provider::Snapchat, $other))->toBeNull();
});

/* A caller that must choose without further information should land on the agency's own credentials. */
it('lists the agency connection before client-granted ones', function (): void {
    $scenario = workspaceScenario();
    $repository = app(ProviderConnectionRepository::class);
    $workspaceId = new WorkspaceId((string) $scenario->workspace->getKey());

    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::client(clientIn($scenario)), 'A client'));
    $repository->save(connection($scenario, Provider::Meta, ConnectionOwner::agency(), 'Zulu agency account'));

    $owners = array_map(
        static fn (ProviderConnection $connection): ConnectionOwnerType => $connection->owner->type,
        $repository->listForProvider($workspaceId, Provider::Meta),
    );

    expect($owners)->toBe([ConnectionOwnerType::Agency, ConnectionOwnerType::Client]);
});

it('refuses to store an owner the database would reject', function (): void {
    expect(fn () => ConnectionOwner::reconstitute(ConnectionOwnerType::Client, null))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => ConnectionOwner::reconstitute(
        ConnectionOwnerType::Agency,
        new ClientId('0199bd00-0000-7000-8000-000000000001'),
    ))->toThrow(InvalidArgumentException::class);
});
