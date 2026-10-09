<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Workspace\WorkspaceId;

/**
 * One store a scheduled pass should read, with its workspace attached.
 *
 * Attached rather than left to the caller, for the same reason the advertising scheduler's targets carry
 * theirs: a scheduler that enumerated workspaces itself would pay a query per workspace and would have one
 * more opportunity to scope a write to the wrong tenant.
 */
final readonly class StoreReadTarget
{
    public function __construct(
        public WorkspaceId $workspaceId,
        public string $storeId,
        public string $name,
    ) {}
}
