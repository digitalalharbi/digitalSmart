<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Workspace\WorkspaceId;

/**
 * An external account some project has asked to be kept in sync.
 *
 * Carries its workspace, because every downstream write is scoped by it and a
 * sync that had to look the workspace up again would be one query away from
 * crossing a tenant boundary.
 */
final readonly class SyncTarget
{
    public function __construct(
        public WorkspaceId $workspaceId,
        public ExternalAccountId $externalAccountId,
        public Provider $provider,
    ) {}
}
