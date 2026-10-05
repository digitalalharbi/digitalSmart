<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\DTO\BindingView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;

/**
 * Which external accounts a project may read.
 *
 * Guarded by project *visibility*, not by workspace settings access: a media
 * buyer assigned to a project should see which accounts feed it, without being
 * able to administer integrations.
 */
final readonly class ListProjectBindings
{
    public function __construct(
        private IntegrationReadModel $readModel,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<BindingView> */
    public function __invoke(ProjectId $projectId): array
    {
        $this->guard->ensureAny(Permission::ProjectsViewAll, Permission::ProjectsViewAssigned);
        $this->guard->ensureProjectVisible($projectId);

        return $this->readModel->bindingsForProject($this->actor->workspaceId(), $projectId);
    }
}
