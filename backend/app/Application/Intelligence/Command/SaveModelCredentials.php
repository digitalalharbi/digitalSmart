<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Command;

use App\Application\Intelligence\Contract\ModelCredentialStore;
use App\Application\Intelligence\Contract\ModelRouter;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Set, change or remove the workspace's own model key and its strong / fast model names. Administrators only; the
 * key is never echoed back, and the audit trail records that it changed, never what it is.
 */
final readonly class SaveModelCredentials
{
    public function __construct(
        private ModelCredentialStore $store,
        private ModelRouter $router,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    /** @return array{credentials: array<string, mixed>, routing: list<array<string, string>>} */
    public function __invoke(?string $apiKey, ?string $strongModel, ?string $fastModel, bool $remove = false): array
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($remove) {
            $this->store->forget($workspaceId);
        } else {
            $this->store->save($workspaceId, $apiKey, $strongModel, $fastModel, $this->actor->userId()->value);
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ModelCredentialsUpdated,
            auditableType: 'workspace_model_credentials',
            auditableId: $workspaceId,
            summary: $remove ? 'Removed the workspace model key' : 'Updated the workspace model settings',
            changes: ['key_changed' => $apiKey !== null || $remove, 'strong_model' => $strongModel, 'fast_model' => $fastModel],
        ));

        return ['credentials' => $this->store->describe($workspaceId), 'routing' => $this->router->status($workspaceId)];
    }
}
