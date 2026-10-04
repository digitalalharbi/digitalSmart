<?php

declare(strict_types=1);

namespace App\Application\Client\Command;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientInput;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientProfile;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Identity\Email;
use App\Domain\Shared\ValueObject\Slug;

final readonly class UpdateClient
{
    public function __construct(
        private ClientRepository $clients,
        private ClientReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ClientId $clientId, ClientInput $input): ClientView
    {
        $this->guard->ensure(Permission::ClientsUpdate);

        $workspaceId = $this->actor->workspaceId();
        $client = $this->clients->findInWorkspace($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client');

        $before = ['name' => $client->name(), 'slug' => $client->slug()->value];

        $client->rename($input->name);

        if ($input->slug !== null) {
            $client->changeSlug(new Slug($input->slug));
        }

        $client->updateProfile(new ClientProfile(
            legalName: $input->legalName,
            industry: $input->industry,
            website: $input->website,
            primaryContactName: $input->primaryContactName,
            primaryContactEmail: $input->primaryContactEmail === null ? null : new Email($input->primaryContactEmail),
            primaryContactPhone: $input->primaryContactPhone,
            defaultCurrency: $input->defaultCurrency === null ? null : strtoupper($input->defaultCurrency),
            notes: $input->notes,
        ));

        $this->clients->save($client);

        $after = ['name' => $client->name(), 'slug' => $client->slug()->value];

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ClientUpdated,
            auditableType: 'client',
            auditableId: $clientId->value,
            summary: sprintf('Updated client "%s".', $client->name()),
            changes: ['before' => $before, 'after' => $after],
        ));

        return $this->readModel->find($workspaceId, $clientId) ?? throw ResourceNotFound::of('Client');
    }
}
