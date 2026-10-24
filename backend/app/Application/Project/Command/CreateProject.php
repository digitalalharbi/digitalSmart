<?php

declare(strict_types=1);

namespace App\Application\Project\Command;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectInput;
use App\Application\Project\DTO\ProjectView;
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
use App\Domain\Project\Project;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;
use App\Domain\Saas\Limit;
use App\Domain\Shared\ValueObject\Slug;
use DateTimeImmutable;

final readonly class CreateProject
{
    public function __construct(
        private ProjectRepository $projects,
        private ClientRepository $clients,
        private ProjectReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private AuditRecorder $audit,
        private EntitlementGuard $entitlements,
    ) {}

    public function __invoke(ProjectInput $input): ProjectView
    {
        $this->guard->ensure(Permission::ProjectsCreate);

        $workspaceId = $this->actor->workspaceId();
        $clientId = new ClientId($input->clientId);

        // The client must belong to this workspace. Looking it up through the
        // scoped repository makes a cross-workspace parent impossible.
        $client = $this->clients->findInWorkspace($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client');

        $projectId = new ProjectId($this->ids->generate());

        $project = Project::open(
            id: $projectId,
            workspaceId: $workspaceId,
            clientId: $client->id,
            name: $input->name,
            slug: $this->resolveSlug($input, $projectId),
            currency: $input->currency,
            description: $input->description,
            startsOn: $this->toDate($input->startsOn),
            endsOn: $this->toDate($input->endsOn),
            isDemo: $input->isDemo,
        );

        $this->entitlements->claim(Limit::Projects, fn () => $this->projects->save($project));

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::ProjectCreated,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Created project "%s" for %s.', $project->name(), $client->name()),
            changes: ['name' => $project->name(), 'client_id' => $client->id->value],
        ));

        // Creators see their new project even without workspace-wide visibility.
        return $this->readModel->find($workspaceId, $this->actor->userId(), null, $projectId)
            ?? throw ResourceNotFound::of('Project');
    }

    private function resolveSlug(ProjectInput $input, ProjectId $projectId): Slug
    {
        $workspaceId = $this->actor->workspaceId();
        $fallback = 'project-'.substr($projectId->value, 0, 8);

        if ($input->slug !== null) {
            return new Slug($input->slug);
        }

        $base = Slug::fromText($input->name, $fallback);

        if (! $this->projects->existsWithSlug($workspaceId, $base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            $candidate = new Slug($base->value.'-'.$suffix);

            if (! $this->projects->existsWithSlug($workspaceId, $candidate)) {
                return $candidate;
            }
        }

        return new Slug($fallback);
    }

    private function toDate(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
