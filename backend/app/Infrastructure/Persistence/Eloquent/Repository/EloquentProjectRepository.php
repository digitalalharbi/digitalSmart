<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Domain\Client\ClientId;
use App\Domain\Project\Project;
use App\Domain\Project\ProjectId;
use App\Domain\Project\ProjectStatus;
use App\Domain\Project\Repository\ProjectRepository;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Persistence\Eloquent\Model\Project as ProjectModel;
use DateTimeImmutable;
use DateTimeInterface;

final class EloquentProjectRepository implements ProjectRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ProjectId $projectId): ?Project
    {
        $model = ProjectModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->whereKey($projectId->value)
            ->first();

        return $model instanceof ProjectModel ? $this->toDomain($model) : null;
    }

    public function existsWithSlug(WorkspaceId $workspaceId, Slug $slug, ?ProjectId $excluding = null): bool
    {
        return ProjectModel::query()
            ->where('workspace_id', $workspaceId->value)
            ->where('slug', $slug->value)
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding?->value))
            ->exists();
    }

    public function save(Project $project): void
    {
        $model = ProjectModel::query()->whereKey($project->id->value)->first() ?? new ProjectModel;

        // See EloquentClientRepository::save() for why this is forceFill.
        $model->forceFill([
            'id' => $project->id->value,
            'workspace_id' => $project->workspaceId->value,
            'client_id' => $project->clientId->value,
            'name' => $project->name(),
            'slug' => $project->slug()->value,
            'description' => $project->description(),
            'status' => $project->status()->value,
            'currency' => $project->currency(),
            'starts_on' => $project->startsOn()?->format('Y-m-d'),
            'ends_on' => $project->endsOn()?->format('Y-m-d'),
            'is_demo' => $project->isDemo,
        ])->save();
    }

    public function remove(Project $project): void
    {
        ProjectModel::query()->whereKey($project->id->value)->delete();
    }

    private function toDomain(ProjectModel $model): Project
    {
        return Project::reconstitute(
            id: new ProjectId((string) $model->getKey()),
            workspaceId: new WorkspaceId((string) $model->getAttribute('workspace_id')),
            clientId: new ClientId((string) $model->getAttribute('client_id')),
            name: (string) $model->getAttribute('name'),
            slug: new Slug((string) $model->getAttribute('slug')),
            status: ProjectStatus::from((string) $model->getAttribute('status')),
            currency: (string) $model->getAttribute('currency'),
            description: $this->nullableString($model->getAttribute('description')),
            startsOn: $this->toDate($model->getAttribute('starts_on')),
            endsOn: $this->toDate($model->getAttribute('ends_on')),
            isDemo: (bool) $model->getAttribute('is_demo'),
        );
    }

    private function toDate(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
