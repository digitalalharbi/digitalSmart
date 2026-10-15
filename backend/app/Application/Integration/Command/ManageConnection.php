<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\Contract\ProviderAdapterRegistry;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\ExternalAccount;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\ProviderCapability;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use SensitiveParameter;

/**
 * Discover, reconnect and disconnect one *specific* connection.
 *
 * The provider-keyed commands answer "the agency's connection to Meta". Once a workspace holds several
 * per platform, those three verbs need a connection id — and resolving by provider instead would run a
 * client's discovery on the agency's token, or disconnect the agency's when asked to disconnect a client's.
 */
final readonly class ManageConnection
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private ExternalAccountRepository $accounts,
        private ProviderAdapterRegistry $adapters,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private AuditRecorder $audit,
    ) {}

    /** @return list<ExternalAccount> */
    public function discover(string $connectionId): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);
        $connection = $this->find($connectionId);
        $adapter = $this->adapters->for($connection->provider);

        if (! $adapter->supports(ProviderCapability::AccountDiscovery)) {
            throw ProviderUnavailable::capabilityUnsupported($connection->provider, 'account discovery');
        }

        $workspaceId = $this->actor->workspaceId();
        $saved = [];

        foreach ($adapter->discoverAccounts($connection->credentials()) as $candidate) {
            $existing = $this->accounts->findByExternalId($workspaceId, $connection->id, $candidate->externalId);

            if ($existing instanceof ExternalAccount) {
                $existing->refreshFrom($candidate->name, $candidate->currency, $candidate->timezone, $candidate->status);
                $account = $existing;
            } else {
                $account = ExternalAccount::discovered(
                    id: new ExternalAccountId($this->ids->generate()),
                    workspaceId: $workspaceId,
                    connectionId: $connection->id,
                    provider: $connection->provider,
                    externalId: $candidate->externalId,
                    name: $candidate->name,
                    currency: $candidate->currency,
                    timezone: $candidate->timezone,
                    status: $candidate->status,
                );
            }

            $this->accounts->save($account);
            $saved[] = $account;
        }

        $connection->recordHealthy($this->clock->now());
        $this->connections->save($connection);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationAccountsDiscovered,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Discovered %d account(s) on %s.', count($saved), $connection->name()),
            changes: ['provider' => $connection->provider->value, 'count' => count($saved)],
        ));

        return $saved;
    }

    /** @param array<string, string> $credentialValues */
    public function reconnect(string $connectionId, #[SensitiveParameter] array $credentialValues): ProviderConnection
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);
        $connection = $this->find($connectionId);

        $connection->replaceCredentials(new Credentials($credentialValues));
        $this->connections->save($connection);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationConnectionReconnected,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Replaced the credentials on %s.', $connection->name()),
            changes: [
                'provider' => $connection->provider->value,
                'credential_keys' => $connection->credentials()->describe(),
            ],
        ));

        return $connection;
    }

    public function disconnect(string $connectionId): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);
        $connection = $this->find($connectionId);

        $connection->revoke($this->clock->now());
        $connection->replaceCredentials(Credentials::empty());
        $this->connections->save($connection);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationDisconnected,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Disconnected %s and destroyed its stored credentials.', $connection->name()),
            changes: ['provider' => $connection->provider->value],
        ));
    }

    private function find(string $connectionId): ProviderConnection
    {
        return $this->connections->findInWorkspace($this->actor->workspaceId(), new ProviderConnectionId($connectionId))
            ?? throw ResourceNotFound::of('Provider connection');
    }
}
