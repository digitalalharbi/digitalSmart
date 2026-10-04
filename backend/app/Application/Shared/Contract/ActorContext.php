<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Domain\Access\PermissionSet;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Who is acting, where, and with what rights.
 *
 * Outbound port: the Interface layer resolves this from the authenticated
 * session and the active workspace, and use cases consume it. Authorization is
 * therefore decided in the Application layer, never in a controller and never
 * by the client.
 */
interface ActorContext
{
    /**
     * Whether an acting user and workspace are established for this request.
     *
     * False means the caller has no active membership of any workspace, which
     * the guard treats as holding no permissions at all.
     */
    public function isBound(): bool;

    public function userId(): UserId;

    public function workspaceId(): WorkspaceId;

    public function permissions(): PermissionSet;

    /**
     * Project ids the actor is explicitly assigned to.
     *
     * @return list<string>
     */
    public function assignedProjectIds(): array;

    public function isAssignedTo(ProjectId $projectId): bool;
}
