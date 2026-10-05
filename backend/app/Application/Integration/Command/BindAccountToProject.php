<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\IntegrationBindingId;
use App\Domain\Integration\ProjectIntegrationBinding;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Project\ProjectId;
use App\Domain\Project\Repository\ProjectRepository;
use App\Domain\Shared\Exception\AccountNotBindable;

/**
 * Let one project read one external ad account.
 *
 * This is the grant that the whole isolation model turns on, so it is deliberate
 * about what it checks, in this order:
 *
 *   1. the actor may manage integrations at all;
 *   2. the project exists **in this workspace** (a cross-tenant project is 404);
 *   3. the account exists **in this workspace** (likewise);
 *   4. the account is in a state worth binding.
 *
 * Both lookups go through workspace-scoped repositories, so a binding between
 * two tenants cannot be constructed even by someone crafting the request by hand.
 */
final readonly class BindAccountToProject
{
    public function __construct(
        private IntegrationBindingRepository $bindings,
        private ExternalAccountRepository $accounts,
        private ProjectRepository $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ProjectId $projectId, ExternalAccountId $accountId): ProjectIntegrationBinding
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId();

        $project = $this->projects->findInWorkspace($workspaceId, $projectId)
            ?? throw ResourceNotFound::of('Project');

        $account = $this->accounts->findInWorkspace($workspaceId, $accountId)
            ?? throw ResourceNotFound::of('External account');

        if (! $account->isBindable()) {
            throw AccountNotBindable::because($account->name(), $account->status()->label());
        }

        $existing = $this->bindings->findForPair($workspaceId, $projectId, $accountId);

        if ($existing instanceof ProjectIntegrationBinding) {
            // Idempotent: binding twice is not an error, it is a no-op that
            // leaves the original grant and its history untouched.
            return $existing;
        }

        $binding = ProjectIntegrationBinding::bind(
            id: new IntegrationBindingId($this->ids->generate()),
            workspaceId: $workspaceId,
            projectId: $projectId,
            externalAccountId: $accountId,
            at: $this->clock->now(),
        );

        $this->bindings->save($binding);

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::IntegrationAccountBound,
            auditableType: 'project',
            auditableId: $projectId->value,
            summary: sprintf('Gave "%s" access to %s.', $project->name(), $account->name()),
            changes: [
                'project_id' => $projectId->value,
                'external_account_id' => $accountId->value,
                'provider' => $account->provider->value,
            ],
        ));

        return $binding;
    }
}
