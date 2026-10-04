<?php

declare(strict_types=1);

namespace App\Application\Client\Command;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientStatus;
use App\Domain\Client\Repository\ClientRepository;

final readonly class ChangeClientStatus
{
    public function __construct(
        private ClientRepository $clients,
        private ClientReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ClientId $clientId, ClientStatus $status): ClientView
    {
        $this->guard->ensure(Permission::ClientsUpdate);

        $workspaceId = $this->actor->workspaceId();
        $client = $this->clients->findInWorkspace($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client');

        $from = $client->status();
        // The domain rejects an illegal transition; the Interface layer turns
        // that into a 422 rather than a silent no-op.
        $client->changeStatus($status);

        $this->clients->save($client);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ClientStatusChanged,
            auditableType: 'client',
            auditableId: $clientId->value,
            summary: sprintf('Client "%s" moved from %s to %s.', $client->name(), $from->value, $status->value),
            changes: ['from' => $from->value, 'to' => $status->value],
        ));

        return $this->readModel->find($workspaceId, $clientId) ?? throw ResourceNotFound::of('Client');
    }
}
