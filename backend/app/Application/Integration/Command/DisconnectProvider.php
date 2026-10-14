<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Revoke a connection and destroy its stored credentials.
 *
 * The connection row, its discovered accounts and their bindings survive, so
 * the history of what was once read stays intact and re-connecting later does
 * not require rebuilding every project's bindings by hand. What goes is the
 * ability to reach the provider.
 */
final readonly class DisconnectProvider
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Provider $provider): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();
        $connection = $this->connections->agencyConnection($workspaceId, $provider)
            ?? throw ResourceNotFound::of('Provider connection');

        $connection->revoke($this->clock->now());
        $connection->replaceCredentials(Credentials::empty());

        $this->connections->save($connection);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationDisconnected,
            auditableType: 'provider_connection',
            auditableId: $connection->id->value,
            summary: sprintf('Disconnected %s and destroyed its stored credentials.', $provider->label()),
            changes: ['provider' => $provider->value],
        ));
    }
}
