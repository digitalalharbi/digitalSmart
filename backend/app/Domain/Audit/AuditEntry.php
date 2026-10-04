<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/** One recorded action. Immutable by construction — an audit trail is never edited. */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ?WorkspaceId $workspaceId,
        public ?UserId $actorId,
        public AuditAction $action,
        public ?string $auditableType = null,
        public ?string $auditableId = null,
        public ?string $summary = null,
        public array $changes = [],
        public array $context = [],
    ) {}
}
