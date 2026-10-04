<?php

declare(strict_types=1);

namespace App\Application\Client\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\ClientId;
use App\Domain\Client\Repository\ClientRepository;

/**
 * Soft-deletes a client.
 *
 * Spend history, reports and audit entries must survive: a hard delete would
 * destroy the record of money that was actually spent on a client's behalf.
 */
final readonly class DeleteClient
{
    public function __construct(
        private ClientRepository $clients,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ClientId $clientId): void
    {
        $this->guard->ensure(Permission::ClientsDelete);

        $workspaceId = $this->actor->workspaceId();
        $client = $this->clients->findInWorkspace($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client');

        $this->clients->remove($client);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ClientDeleted,
            auditableType: 'client',
            auditableId: $clientId->value,
            summary: sprintf('Deleted client "%s".', $client->name()),
        ));
    }
}
