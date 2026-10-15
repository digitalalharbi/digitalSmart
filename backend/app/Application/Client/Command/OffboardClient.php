<?php

declare(strict_types=1);

namespace App\Application\Client\Command;

use App\Application\Client\Contract\ClientOffboarding;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientStatus;
use App\Domain\Client\Repository\ClientRepository;

/**
 * A client leaves. Everything recurring stops; nothing they produced goes.
 *
 * `preview` and `apply` return the same shape, so what a person confirms is a description of what happens.
 * The status change goes through the aggregate, so an illegal move — archived straight back to offboarded —
 * is refused by the domain rather than by this command.
 */
final readonly class OffboardClient
{
    public function __construct(
        private ClientRepository $clients,
        private ClientOffboarding $offboarding,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $clientId, string $reason): array
    {
        $this->guard->ensure(Permission::ClientsUpdate);

        $client = $this->find($clientId);
        $from = $client->status();
        $client->changeStatus(ClientStatus::Offboarded);

        $summary = $this->offboarding->apply(
            $this->actor->workspaceId()->value,
            $clientId,
            $this->actor->userId()->value,
            $reason,
            $this->clock->now(),
        );

        $this->clients->save($client);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientOffboarded,
            auditableType: 'client',
            auditableId: $clientId,
            summary: sprintf('Offboarded "%s". Recurring work stopped; history kept.', $client->name()),
            changes: ['from' => $from->value, 'to' => ClientStatus::Offboarded->value] + $summary,
            context: ['reason' => $reason],
        ));

        return $summary;
    }

    /** @return array<string, mixed> */
    public function preview(string $clientId): array
    {
        $this->guard->ensure(Permission::ClientsUpdate);
        $this->find($clientId);

        return $this->offboarding->preview($this->actor->workspaceId()->value, $clientId);
    }

    private function find(string $clientId): \App\Domain\Client\Client
    {
        return $this->clients->findInWorkspace($this->actor->workspaceId(), new ClientId($clientId))
            ?? throw ResourceNotFound::of('Client');
    }
}
