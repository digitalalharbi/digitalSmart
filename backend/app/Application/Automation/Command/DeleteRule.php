<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

final readonly class DeleteRule
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $rule = $this->rules->find($workspaceId, $id) ?? throw ResourceNotFound::of('Rule');

        $this->rules->delete($workspaceId, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::AutomationRuleDeleted,
            auditableType: 'automation_rule',
            auditableId: $id,
            summary: sprintf('Archived the rule "%s"', $rule->name),
            changes: ['kinds' => ['archived'], 'before' => SaveRule::shape($rule), 'after' => null],
        ));
    }
}
