<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;
use Illuminate\Support\Facades\DB;

final readonly class PermissionAuthorizationGuard implements AuthorizationGuard
{
    public function __construct(private ActorContext $actor) {}

    public function ensure(Permission $permission): void
    {
        if (! $this->actor->isBound() || ! $this->actor->permissions()->has($permission)) {
            throw $this->actor->isBound()
                ? AuthorizationDenied::missingPermission($permission)
                : AuthorizationDenied::noActiveWorkspace();
        }
    }

    public function ensureAny(Permission ...$permissions): void
    {
        if (! $this->actor->isBound()) {
            throw AuthorizationDenied::noActiveWorkspace();
        }

        if (! $this->actor->permissions()->hasAny(...$permissions)) {
            throw AuthorizationDenied::missingAnyPermission(array_values($permissions));
        }
    }

    /**
     * Workspace-wide visibility wins; otherwise the actor must hold
     * projects.view_assigned *and* actually be assigned to this project.
     */
    public function ensureProjectVisible(ProjectId $projectId): void
    {
        if (! $this->actor->isBound()) {
            throw AuthorizationDenied::noActiveWorkspace();
        }

        $permissions = $this->actor->permissions();

        if ($permissions->has(Permission::ProjectsViewAll)) {
            /*
             * "Every project" means every project **in the active workspace**. Returning here without asking which
             * workspace the project is in let an owner of one tenant list the members of another tenant's project by
             * its id (found by the tenant route matrix). Answered as not found, so the id's existence is not confirmed.
             */
            $inWorkspace = DB::table('projects')
                ->where('id', $projectId->value)
                ->where('workspace_id', $this->actor->workspaceId()->value)
                ->whereNull('deleted_at')
                ->exists();

            if (! $inWorkspace) {
                throw ResourceNotFound::of('Project');
            }

            return;
        }

        if (! $permissions->has(Permission::ProjectsViewAssigned)) {
            throw AuthorizationDenied::missingAnyPermission([
                Permission::ProjectsViewAll,
                Permission::ProjectsViewAssigned,
            ]);
        }

        if (! $this->actor->isAssignedTo($projectId)) {
            throw AuthorizationDenied::notAssignedToProject();
        }
    }

    public function allows(Permission $permission): bool
    {
        return $this->actor->isBound() && $this->actor->permissions()->has($permission);
    }
}
