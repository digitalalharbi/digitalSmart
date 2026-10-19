<?php

declare(strict_types=1);

namespace App\Application\Automation\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\Contract\RuleExecutionLog;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;

/**
 * The automation dashboard's reads: the summary cards, the action history and the change log — all inside the
 * reader's own projects. A reader limited to some projects sees those projects' actions and the rules that cover
 * them; nothing of a client they cannot open.
 */
final readonly class GetAutomationCenter
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private RuleExecutionLog $log,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function summary(int $days): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $now = $this->clock->now();
        $from = $now->modify(sprintf('-%d days', $days));
        $counts = $this->log->counts($this->workspace(), $this->visibility->visibleProjectIds(), $from, $now);
        $card = static fn (string ...$keys): array => [
            'now' => array_sum(array_map(static fn (string $k): int => $counts[$k]['now'] ?? 0, $keys)),
            'before' => array_sum(array_map(static fn (string $k): int => $counts[$k]['before'] ?? 0, $keys)),
        ];

        $rules = $this->visible();
        $acting = $this->log->rulesActedSince($this->workspace(), $now->modify('-30 days'));

        return [
            'days' => $days,
            'cards' => [
                'all' => $card('all'),
                'turn_on' => $card('action:propose_resume'),
                'turn_off' => $card('action:propose_pause'),
                'budget' => $card('action:propose_budget_cut', 'action:propose_budget_increase'),
                'bid' => $card('action:propose_bid_change'),
                'failed' => $card('result:failed'),
                'waiting' => $card('result:waiting_approval'),
            ],
            'rules' => [
                'total' => count($rules),
                'active' => count(array_filter($rules, static fn (RuleView $r): bool => $r->isActive)),
                /* Active, and has not acted in thirty days — a rule that may no longer be pulling its weight. */
                'inactive_30d' => count(array_filter($rules, static fn (RuleView $r): bool => $r->isActive && ! in_array($r->id, $acting, true))),
            ],
        ];
    }

    /**
     * @param  array{rule_id?: string|null, result?: string|null, provider?: string|null, client_id?: string|null, project_id?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function history(array $filters, int $days, int $limit = 200): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        return $this->log->history($this->workspace(), $this->visibility->visibleProjectIds(), $filters, $this->clock->now()->modify(sprintf('-%d days', $days)), $limit);
    }

    /** @return list<array<string, mixed>> */
    public function changeLog(int $days, int $limit = 200): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $ids = $this->visibility->visibleProjectIds() === null ? null : array_map(static fn (RuleView $r): string => $r->id, $this->visible());

        return $this->log->changeLog($this->workspace(), $ids, $this->clock->now()->modify(sprintf('-%d days', $days)), $limit);
    }

    /** @return list<RuleView> */
    private function visible(): array
    {
        return $this->rules->visibleTo($this->workspace(), $this->visibility->visibleProjectIds());
    }

    private function workspace(): string
    {
        return $this->actor->workspaceId()->value;
    }
}
