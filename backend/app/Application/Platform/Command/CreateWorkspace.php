<?php

declare(strict_types=1);

namespace App\Application\Platform\Command;

use App\Application\Platform\Contract\PlatformReadModel;
use App\Application\Platform\Contract\WorkspaceCreator;
use App\Application\Platform\DTO\CreatedWorkspace;
use App\Application\Platform\DTO\NewWorkspace;
use App\Application\Platform\Exception\PlatformRefused;
use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Workspace\WorkspaceId;

/**
 * Create a tenant from the platform console: the workspace, its roles, its plan and state, and its owner.
 *
 * Never from a public request — a request for access is read and approved by a person first, and this is the step after. No
 * provider connection comes with it: a new workspace trusts nothing until its own people connect it.
 */
final readonly class CreateWorkspace
{
    public function __construct(
        private WorkspaceCreator $creator,
        private PlatformReadModel $platform,
        private EntitlementStore $store,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(NewWorkspace $workspace, string $operator): CreatedWorkspace
    {
        if ($this->platform->slugTaken($workspace->slug)) {
            throw PlatformRefused::slugTaken($workspace->slug);
        }

        if (! $this->store->planExists($workspace->planCode)) {
            throw PlatformRefused::unknownPlan($workspace->planCode);
        }

        if (! in_array($workspace->status, [SubscriptionStatus::Trial, SubscriptionStatus::Active], true)) {
            throw PlatformRefused::newWorkspaceStatus();
        }

        if ($workspace->status === SubscriptionStatus::Trial
            && ($workspace->trialEndsAt === null || $workspace->trialEndsAt <= $this->clock->now())) {
            throw PlatformRefused::trialNeedsEnd();
        }

        $created = $this->creator->create($workspace);

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($created->workspaceId),
            actorId: null,
            action: AuditAction::WorkspaceCreated,
            auditableType: 'workspace',
            auditableId: $created->workspaceId,
            summary: sprintf('%s created the workspace with %s as its owner.', $operator, $created->ownerEmail),
            changes: [
                'type' => $workspace->type->value,
                'plan' => $workspace->planCode,
                'status' => $workspace->status->value,
                'owner_account' => $created->oneTimePassword === null ? 'existing' : 'created',
                'operator' => $operator,
            ],
        ));

        return $created;
    }
}
