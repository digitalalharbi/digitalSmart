<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderConnection;
use App\Domain\Integration\ProviderConnectionId;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use SensitiveParameter;

/**
 * Store (or replace) a provider's credentials for this workspace.
 *
 * The audit entry records *which* credential keys were supplied — never their
 * values. Knowing that someone changed the Google developer token, and when, is
 * the point; knowing the token is a liability.
 */
final readonly class ConfigureConnection
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, string> $credentialValues */
    public function __invoke(
        Provider $provider,
        #[SensitiveParameter] array $credentialValues,
        ?string $name = null,
    ): ProviderConnection {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();
        $credentials = new Credentials($credentialValues);
        $existing = $this->connections->findForProvider($workspaceId, $provider);

        if ($existing instanceof ProviderConnection) {
            $existing->replaceCredentials($credentials);

            if ($name !== null) {
                $existing->rename($name);
            }

            $connection = $existing;
            $action = AuditAction::IntegrationCredentialsUpdated;
        } else {
            $connection = ProviderConnection::register(
                id: new ProviderConnectionId($this->ids->generate()),
                workspaceId: $workspaceId,
                provider: $provider,
                name: $name ?? $provider->label(),
                credentials: $credentials,
            );
            $action = AuditAction::IntegrationConnected;
        }

        $this->connections->save($connection);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Stored credentials for %s.', $provider->label()),
            // Key names only. Values never reach the audit log.
            changes: [
                'provider' => $provider->value,
                'credential_keys' => $credentials->describe(),
                'status' => $connection->status()->value,
            ],
        ));

        return $connection;
    }
}
