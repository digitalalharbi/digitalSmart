<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Contract\AgentActors;
use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\Contract\RuleExecutionLog;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\RuleEvaluator;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMode;
use App\Domain\Identity\UserId;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteSupport;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use Throwable;

/**
 * The rules routine. Every fifteen minutes; each rule decides whether it is due (continuous, hourly, or daily at a
 * time in its own time zone).
 *
 * For a due rule: read the campaigns in its scope (client, project, platform, account, campaign) with the same query
 * the Campaigns screen runs; evaluate its conditions (AND / OR); keep how long it has held for each campaign; and
 * once it has held for as many days as it asks, act **as its mode allows**:
 *
 * - Monitor — the card on Today and a "completed · notified" line in the history. Nothing is proposed.
 * - Recommend — a proposal through the tool gateway, as the workspace's agent identity: approval, the project's
 *   budget limit, no stacking and the day's cooldown all apply. Recorded as "waiting approval".
 * - Auto — not reachable: a rule cannot be saved in Auto until the owner authorises it (`RuleMode`).
 *
 * Before acting: the rule's own cooldown on that campaign; a conflicting move by another rule (one raised the budget,
 * this would cut it) — the later rule stands down; and the platform's capability — an action whose write is not
 * documented, or a bid change (verified nowhere), is recorded as "unsupported" with the reason, never attempted.
 * Nothing here changes a platform.
 */
final readonly class RunAutomationRules
{
    private const int ACTIONS_PER_RULE = 10;

    /** Opposite moves, so two rules never undo each other inside a cooldown. */
    private const array OPPOSITE = [
        'propose_pause' => ['propose_resume', 'propose_budget_increase'],
        'propose_resume' => ['propose_pause', 'propose_budget_cut'],
        'propose_budget_cut' => ['propose_budget_increase', 'propose_resume'],
        'propose_budget_increase' => ['propose_budget_cut', 'propose_pause'],
    ];

    public function __construct(
        private AutomationRuleRepository $rules,
        private RuleExecutionLog $executions,
        private GetCampaignControl $campaigns,
        private AgentActors $identities,
        private AgentRunLog $runs,
        private ActorContextBinder $binder,
        private ToolGateway $tools,
        private Clock $clock,
    ) {}

    /** @return array{workspaces: int, rules: int, acted: int, refused: int} */
    public function __invoke(?string $workspaceId = null, bool $force = false): array
    {
        $totals = ['workspaces' => 0, 'rules' => 0, 'acted' => 0, 'refused' => 0];

        foreach ($this->runs->workspaces($workspaceId) as $workspace) {
            $totals['workspaces']++;
            $run = null;

            try {
                $this->binder->bind(new UserId($this->identities->ensure($workspace)), new WorkspaceId($workspace));
                $run = $this->runs->start($workspace, 'automation');
                $done = ['rules' => 0, 'acted' => 0, 'refused' => []];
                $now = $this->clock->now();

                foreach ($this->rules->visibleTo($workspace, null, activeOnly: true) as $rule) {
                    if (! $force && ! $rule->schedule->isDue($rule->lastRunAt, $now, $rule->scheduleTime, $rule->timezone)) {
                        continue;
                    }

                    $done['rules']++;
                    $this->rules->recordRun($rule->id, $this->rule($workspace, $rule, $done));
                }

                $this->runs->progress($run, 'completed', $done['rules'], $done['acted'], ['acted' => $done['acted'], 'refused' => $done['refused']]);
                $totals['rules'] += $done['rules'];
                $totals['acted'] += $done['acted'];
                $totals['refused'] += array_sum($done['refused']);
            } catch (Throwable $failure) {
                report($failure);

                if ($run !== null) {
                    $this->runs->fail($run, 'The rules could not be evaluated.');
                }
            } finally {
                $this->binder->clear();
            }
        }

        return $totals;
    }

    /**
     * @param  array{rules: int, acted: int, refused: array<string, int>}  $done
     * @return array<string, int|string>
     */
    private function rule(string $workspace, RuleView $rule, array &$done): array
    {
        $today = $this->clock->now();
        $hits = [];

        foreach ($this->scope($rule) as $row) {
            $evaluation = RuleEvaluator::evaluate($rule, $row, $today);

            if ($evaluation['holds']) {
                $hits[$row->campaignId] = [$row, $evaluation];
            }
        }

        $streaks = $this->rules->observe($rule->id, array_keys($hits), $today->format('Y-m-d'));
        $result = ['matched' => count($hits), 'completed' => 0, 'waiting_approval' => 0, 'no_change' => 0, 'unsupported' => 0, 'failed' => 0, 'cooling' => 0];
        $acted = 0;

        foreach ($hits as $campaignId => [$row, $evaluation]) {
            $streak = $streaks[$campaignId] ?? ['days' => 0, 'acted' => true];

            if ($streak['acted'] || $streak['days'] < $rule->forDays || $acted >= self::ACTIONS_PER_RULE) {
                continue;
            }

            if ($this->executions->lastActedAt($rule->id, $campaignId, $today->modify(sprintf('-%d hours', $rule->cooldownHours))) !== null) {
                $result['cooling']++;

                continue;
            }

            [$outcome, $reason, $after, $approval] = $this->act($rule, $row, $evaluation, $streak['days'], $today);
            $this->rules->markActed($rule->id, $campaignId);
            $this->executions->record($workspace, [
                'rule_id' => $rule->id,
                'campaign_id' => $campaignId,
                'client_id' => $row->clientId,
                'project_id' => $row->projectId,
                'provider' => $row->provider,
                'object_level' => 'campaign',
                'object_label' => $row->campaignName,
                'action' => $rule->mode === RuleMode::Monitor ? 'notify' : $rule->action->value,
                'before' => [...$evaluation['values'], 'daily_budget' => $row->budgetMinor === null ? null : $row->budgetMinor / 100, 'status' => $row->status->value, 'currency' => $row->currency],
                'after' => $after,
                'result' => $outcome,
                'reason_code' => $reason,
                'approval_id' => $approval,
            ]);

            $result[$outcome]++;

            if (in_array($outcome, ['completed', 'waiting_approval'], true)) {
                $acted++;
                $done['acted']++;
            } else {
                $done['refused'][$reason ?? $outcome] = ($done['refused'][$reason ?? $outcome] ?? 0) + 1;
            }
        }

        return $result;
    }

    /** @return list<CampaignControlRow> */
    private function scope(RuleView $rule): array
    {
        return $this->campaigns->assessAll(new CampaignControlFilter(
            clientIds: $rule->clientId === null ? [] : [$rule->clientId],
            projectIds: $rule->projectId === null ? [] : [$rule->projectId],
            providers: $rule->providers,
            externalAccountIds: $rule->accountIds,
            campaignIds: $rule->campaignIds,
        ), $rule->windowDays)['rows'];
    }

    /**
     * @param  array{values: array<string, float|int|null>, first: array{0: string, 1: string, 2: array<string, int|string|float>}|null}  $evaluation
     * @return array{0: string, 1: string|null, 2: array<string, mixed>, 3: string|null}
     */
    private function act(RuleView $rule, CampaignControlRow $row, array $evaluation, int $days, DateTimeImmutable $now): array
    {
        if ($rule->mode === RuleMode::Monitor || $rule->action === RuleAction::Alert) {
            return ['completed', 'NOTIFIED', ['notified' => true], null];
        }

        if ($rule->action === RuleAction::ProposeBidChange) {
            return ['unsupported', 'BID_WRITE_NOT_VERIFIED', [], null];
        }

        $operation = $rule->action->writeOperation();
        $provider = Provider::tryFrom($row->provider);

        if ($operation !== null && ($provider === null || $provider->writeSupport($operation) !== WriteSupport::Documented)) {
            return ['unsupported', 'PROVIDER_WRITE_NOT_DOCUMENTED', [], null];
        }

        $opposite = self::OPPOSITE[$rule->action->value] ?? [];
        $rival = $this->executions->conflicting($rule->id, $row->campaignId, $opposite, $now->modify(sprintf('-%d hours', $rule->cooldownHours)));

        if ($rival !== null) {
            return ['no_change', 'CONFLICT', ['conflicts_with' => $rival], null];
        }

        $reason = sprintf('Rule "%s": %s Held for %d day(s).', $rule->name, (string) ($evaluation['first'][0] ?? ''), $days);
        $after = [];

        $outcome = match ($rule->action) {
            RuleAction::ProposePause => $this->tools->call('propose_pause_campaign', ['campaign_id' => $row->campaignId, 'reason' => $reason], 'agent', 'automation'),
            RuleAction::ProposeResume => $this->tools->call('propose_resume_campaign', ['campaign_id' => $row->campaignId, 'reason' => $reason], 'agent', 'automation'),
            RuleAction::ProposeBudgetCut, RuleAction::ProposeBudgetIncrease => $this->budget($rule, $row, $reason, $after),
            default => $this->tools->call('create_task', [
                'title' => sprintf('%s — %s', $rule->name, $row->campaignName),
                'description' => $reason,
                ...($row->projectId === null ? [] : ['project_id' => $row->projectId]),
            ], 'agent', 'automation'),
        };

        if (in_array($rule->action, [RuleAction::ProposePause, RuleAction::ProposeResume], true)) {
            $after = ['status' => $rule->action === RuleAction::ProposePause ? 'paused' : 'active'];
        }

        if ($outcome['ok'] ?? false) {
            $approval = $outcome['data']['proposal']['id'] ?? null;

            return [$rule->action === RuleAction::Task ? 'completed' : 'waiting_approval', null, $after, is_string($approval) ? $approval : null];
        }

        $code = (string) ($outcome['error']['code'] ?? 'failed');

        return match ($code) {
            'provider_not_documented', 'not_daily', 'no_daily_budget' => ['unsupported', strtoupper($code), $after, null],
            'already_proposed', 'cooldown', 'duplicate_task', 'unchanged' => ['no_change', strtoupper($code), $after, null],
            default => ['failed', strtoupper($code), $after, null],
        };
    }

    /**
     * @param  array<string, mixed>  $after
     * @return array{ok: bool, data?: array<string, mixed>, error?: array{code: string, message: string}}
     */
    private function budget(RuleView $rule, CampaignControlRow $row, string $reason, array &$after): array
    {
        if ($row->budgetMinor === null || $row->budgetMinor <= 0) {
            return ['ok' => false, 'error' => ['code' => 'no_daily_budget', 'message' => 'No daily budget to change.']];
        }

        $sign = $rule->action === RuleAction::ProposeBudgetIncrease ? 1 : -1;
        $new = (int) round($row->budgetMinor * (100 + $sign * (int) $rule->actionPct) / 100);
        $after = ['daily_budget' => $new / 100];

        return $this->tools->call('propose_budget_change', ['campaign_id' => $row->campaignId, 'daily_budget_minor' => $new, 'reason' => $reason], 'agent', 'automation');
    }
}
