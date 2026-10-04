<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Domain\Access\Permission;
use App\Domain\Project\ProjectId;

/** Enforcement point for permissions. Throws rather than returning false. */
interface AuthorizationGuard
{
    /** @throws \App\Application\Shared\Exception\AuthorizationDenied */
    public function ensure(Permission $permission): void;

    /** @throws \App\Application\Shared\Exception\AuthorizationDenied */
    public function ensureAny(Permission ...$permissions): void;

    /**
     * Ensure the actor may see this project: either workspace-wide visibility,
     * or an explicit assignment.
     *
     * @throws \App\Application\Shared\Exception\AuthorizationDenied
     */
    public function ensureProjectVisible(ProjectId $projectId): void;

    public function allows(Permission $permission): bool;
}
