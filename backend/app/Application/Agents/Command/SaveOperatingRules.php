<?php

declare(strict_types=1);

namespace App\Application\Agents\Command;

use App\Application\Agents\Contract\OperatingRuleRepository;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Set a client's or a project's targets and limits.
 *
 * These are the ceilings no agent recommendation may cross, so who may change them is the same as who may change
 * budgets, and every change is audited with the values it set.
 */
final readonly class SaveOperatingRules
{
    public function __construct(
        private OperatingRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $values */
    public function __invoke(?string $clientId, ?string $projectId, array $values): void
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($projectId !== null) {
            $visible = $this->visibility->visibleProjectIds();

            if (($visible !== null && ! in_array($projectId, $visible, true)) || $this->rules->clientOf($workspaceId, $projectId) === null) {
                throw ResourceNotFound::of('Project');
            }

            $clientId = null;
        }

        $this->rules->save($workspaceId, $this->actor->userId()->value, $clientId, $projectId, $values);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::OperatingRulesSaved,
            auditableType: $projectId === null ? 'client' : 'project',
            auditableId: $projectId ?? $clientId,
            summary: 'Changed the targets and limits agents work to',
            changes: $values,
        ));
    }
}
