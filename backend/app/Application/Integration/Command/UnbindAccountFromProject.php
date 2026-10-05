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
 * Revoke a project's access to an external account.
 *
 * Removing the binding is the revocation: the project stops seeing the account
 * immediately, because visibility is derived from this row rather than cached
 * anywhere.
 */
final readonly class UnbindAccountFromProject
{
    public function __construct(
        private IntegrationBindingRepository $bindings,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, ExternalAccountId $accountId): void
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();

        $binding = $this->bindings->findForPair($workspaceId, $projectId, $accountId)
            ?? throw ResourceNotFound::of('Binding');

        $this->bindings->remove($binding);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationAccountUnbound,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: 'Revoked a project\'s access to an external ad account.',
            changes: [
                'project_id' => $projectId->value,
                'external_account_id' => $accountId->value,
            ],
        ));
    }
}
