<?php

declare(strict_types=1);

namespace App\Application\Client\Command;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientInput;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Client\Client;
use App\Domain\Client\ClientId;
use App\Domain\Client\ClientProfile;
use App\Domain\Client\Repository\ClientRepository;
use App\Domain\Identity\Email;
use App\Domain\Shared\ValueObject\Slug;

final readonly class CreateClient
{
    public function __construct(
        private ClientRepository $clients,
        private ClientReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ClientInput $input): ClientView
    {
        $this->guard->ensure(Permission::ClientsCreate);

        $workspaceId = $this->actor->workspaceId();
        $clientId = new ClientId($this->ids->generate());

        $client = Client::register(
            id: $clientId,
            workspaceId: $workspaceId,
            name: $input->name,
            slug: $this->resolveSlug($input, $clientId),
            profile: new ClientProfile(
                legalName: $input->legalName,
                industry: $input->industry,
                website: $input->website,
                primaryContactName: $input->primaryContactName,
                primaryContactEmail: $input->primaryContactEmail === null ? null : new Email($input->primaryContactEmail),
                primaryContactPhone: $input->primaryContactPhone,
                defaultCurrency: $input->defaultCurrency === null ? null : strtoupper($input->defaultCurrency),
                notes: $input->notes,
            ),
            isDemo: $input->isDemo,
        );

        $this->clients->save($client);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ClientCreated,
            auditableType: 'client',
            auditableId: $clientId->value,
            summary: sprintf('Created client "%s".', $client->name()),
            changes: ['name' => $client->name(), 'slug' => $client->slug()->value],
        ));

        return $this->readModel->find($workspaceId, $clientId) ?? throw ResourceNotFound::of('Client');
    }

    /**
     * Slugs are unique per workspace. A caller-supplied slug that collides is a
     * validation error, but one we derived ourselves is made unique silently —
     * two clients may legitimately share a name.
     */
    private function resolveSlug(ClientInput $input, ClientId $clientId): Slug
    {
        $workspaceId = $this->actor->workspaceId();
        $fallback = 'client-'.substr($clientId->value, 0, 8);

        if ($input->slug !== null) {
            return new Slug($input->slug);
        }

        $base = Slug::fromText($input->name, $fallback);

        if (! $this->clients->existsWithSlug($workspaceId, $base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = new Slug($base->value.'-'.$suffix);

            if (! $this->clients->existsWithSlug($workspaceId, $candidate)) {
                return $candidate;
            }
        }

        return new Slug($fallback);
    }
}
