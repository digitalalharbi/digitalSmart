<?php

declare(strict_types=1);

namespace App\Domain\Project\Repository;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectId;
use App\Domain\Shared\ValueObject\Slug;
use App\Domain\Workspace\WorkspaceId;

interface ProjectRepository
{
    public function findInWorkspace(WorkspaceId $workspaceId, ProjectId $projectId): ?Project;

    public function existsWithSlug(WorkspaceId $workspaceId, Slug $slug, ?ProjectId $excluding = null): bool;

    public function save(Project $project): void;

    public function remove(Project $project): void;
}
