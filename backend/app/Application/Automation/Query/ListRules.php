<?php

declare(strict_types=1);

namespace App\Application\Automation\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

final readonly class ListRules
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<RuleView> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        return $this->rules->visibleTo($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds());
    }
}
