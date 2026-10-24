<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\ClientId;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Integration\ConnectionOwner;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Exception\ConnectionAlreadyExists;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Saas\Limit;
use SensitiveParameter;

/**
 * Record credentials a client granted the agency directly.
 *
 * The agency's own connection is configured from the provider catalogue; this is the other half of the
 * ownership model. A client who will not move an ad account under the agency's Business Manager — or who
 * means to take it back when the contract ends — grants access to *them*, and the product must be able to
 * hold that without pretending it is the agency's.
 *
 * What it refuses is the point: one connection per client per platform (a second is a reconnect), and a
 * client from another workspace is a 404 rather than a quiet cross-tenant write.
 */
final readonly class ConnectClientProvider
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private ClientRepository $clients,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private AuditRecorder $audit,
        private EntitlementGuard $entitlements,
    ) {}

    /** @param array<string, string> $credentialValues */
    public function __invoke(
        Provider $provider,
        string $clientId,
        #[SensitiveParameter] array $credentialValues,
        ?string $name = null,
    ): ProviderConnection {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();
        $client = $this->clients->findInWorkspace($workspaceId, new ClientId($clientId))
            ?? throw ResourceNotFound::of('Client');

        if ($this->connections->forClient($workspaceId, $provider, new ClientId($clientId)) instanceof ProviderConnection) {
            throw ConnectionAlreadyExists::forClient($provider->label());
        }

        $connection = ProviderConnection::register(
            id: new ProviderConnectionId($this->ids->generate()),
            workspaceId: $workspaceId,
            provider: $provider,
            owner: ConnectionOwner::client(new ClientId($clientId)),
            name: $name ?? sprintf('%s — %s', $provider->label(), $client->name()),
            credentials: new Credentials($credentialValues),
        );

        $this->entitlements->claim(Limit::ProviderConnections, fn () => $this->connections->save($connection));

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationConnected,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Recorded a %s connection granted by %s.', $provider->label(), $client->name()),
            changes: [
                'provider' => $provider->value,
                'owner' => 'client',
                'client_id' => $clientId,
                'credential_keys' => $connection->credentials()->describe(),
            ],
        ));

        return $connection;
    }
}
