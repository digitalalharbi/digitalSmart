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
use App\Domain\Integration\ExternalAccount;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Ask a provider which ad accounts these credentials can see.
 *
 * Discovery is idempotent: an account already known is refreshed in place, so
 * re-running never duplicates rows or breaks existing project bindings.
 */
final readonly class DiscoverAccounts
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
    public function __invoke(Provider $provider): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();
        $connection = $this->connections->findForProvider($workspaceId, $provider)
            ?? throw ResourceNotFound::of('Provider connection');

        $adapter = $this->adapters->for($provider);

        if (! $adapter->supports(ProviderCapability::AccountDiscovery)) {
            throw ProviderUnavailable::capabilityUnsupported($provider, 'account discovery');
        }

        // Throws ProviderNotConfigured when credentials are missing, or present
        // but unusable because the provider has not granted us access.
        $discovered = $adapter->discoverAccounts($connection->credentials());

        $saved = [];

        foreach ($discovered as $candidate) {
            $existing = $this->accounts->findByExternalId($workspaceId, $connection->id, $candidate->externalId);

            if ($existing instanceof ExternalAccount) {
                $existing->refreshFrom($candidate->name, $candidate->currency, $candidate->timezone, $candidate->status);
                $account = $existing;
            } else {
                $account = ExternalAccount::discovered(
                    id: new ExternalAccountId($this->ids->generate()),
                    workspaceId: $workspaceId,
                    connectionId: $connection->id,
                    provider: $provider,
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
            summary: sprintf('Discovered %d %s ad account(s).', count($saved), $provider->label()),
            changes: ['provider' => $provider->value, 'count' => count($saved)],
        ));

        return $saved;
    }
}
