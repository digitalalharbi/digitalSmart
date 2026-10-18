<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;

/**
 * Create a rule, or change one.
 *
 * Scope is checked against what the writer may see: a rule cannot be aimed at a project they have no access to,
 * which would otherwise be a way to learn that it exists. A rule can only ever raise an item in the attention
 * queue — it holds no power over a campaign — so the permission that guards it is the one that guards budgets.
 */
final readonly class SaveRule
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(RuleInput $input, ?string $id = null): RuleView
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($id !== null && $this->rules->find($workspaceId, $id) === null) {
            throw ResourceNotFound::of('Rule');
        }

        if ($input->projectId !== null) {
            $visible = $this->visibility->visibleProjectIds();

            if ($visible !== null && ! in_array($input->projectId, $visible, true)) {
                throw ResourceNotFound::of('Project');
            }
        }

        if ($this->rules->nameTaken($workspaceId, $input->name, $id)) {
            throw NameAlreadyUsed::byARule($input->name);
        }

        $saved = $id === null
            ? $this->rules->create($workspaceId, $this->actor->userId()->value, $input)
            : $this->rules->update($workspaceId, $id, $input);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::AutomationRuleSaved,
            auditableType: 'automation_rule',
            auditableId: $saved->id,
            summary: sprintf('%s the rule "%s"', $id === null ? 'Created' : 'Changed', $saved->name),
            changes: ['metric' => $saved->metric->value, 'threshold' => $saved->threshold, 'active' => $saved->isActive],
        ));

        return $saved;
    }
}
