<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Project\ProjectId;

/**
 * Pause or resume syncing for one binding.
 *
 * Distinct from unbinding: pausing keeps the relationship and its history, and
 * is the right action when a project is on hold rather than finished.
 */
final readonly class SetBindingSync
{
    public function __construct(
        private IntegrationBindingRepository $bindings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, ExternalAccountId $accountId, bool $enabled): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();

        $binding = $this->bindings->findForPair($workspaceId, $projectId, $accountId)
            ?? throw ResourceNotFound::of('Binding');

        $enabled ? $binding->resumeSync() : $binding->pauseSync();

        $this->bindings->save($binding);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationSyncToggled,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: $enabled ? 'Resumed syncing for a bound account.' : 'Paused syncing for a bound account.',
            changes: [
                'project_id' => $projectId->value,
                'external_account_id' => $accountId->value,
                'sync_enabled' => $enabled,
            ],
        ));
    }
}
