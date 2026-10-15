<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\Contract\ConnectionDirectory;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/** Take a discovered account out of the review queue, or put it back. Never deletes: the next discovery would find it again. */
final readonly class TriageAccount
{
    public function __construct(
        private ConnectionDirectory $directory,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
        private AuditRecorder $audit,
    ) {}

    public function ignore(string $accountId): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);
        $workspace = $this->actor->workspaceId();

        if (! $this->directory->ignore($workspace->value, $accountId, $this->actor->userId()->value, $this->clock->now())) {
            throw ResourceNotFound::of('Unassigned account');
        }

        $this->record(AuditAction::IntegrationAccountIgnored, $accountId, 'Left an unassigned account alone.');
    }

    public function restore(string $accountId): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        if (! $this->directory->restore($this->actor->workspaceId()->value, $accountId)) {
            throw ResourceNotFound::of('External account');
        }

        $this->record(AuditAction::IntegrationAccountRestored, $accountId, 'Returned an ignored account to the review queue.');
    }

    private function record(AuditAction $action, string $accountId, string $summary): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'external_account',
            auditableId: $accountId,
            summary: $summary,
        ));
    }
}
