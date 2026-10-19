<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Contract\AgentActors;
use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\RuleEvaluator;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Automation\RuleAction;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * The rules routine: record how long each rule has held for each campaign, and — once it has held for as many days as
 * the rule asks, and only as far as the rule's trust allows — carry out its action.
 *
 * The action is never a platform change. It goes through the same tool gateway the agents use, as the workspace's
 * agent identity: a proposal waits for a person with the right to approve it, and the proposal limits apply (no
 * stacking, a day's cooldown, at most the project's largest change); a task is only opened at trust level 3. Each
 * streak acts once; a campaign that stops breaching and breaches again starts a new streak.
 */
final readonly class RunAutomationRules
{
    private const int ACTIONS_PER_RULE = 10;

    public function __construct(
        private AutomationRuleRepository $rules,
        private GetCampaignControl $campaigns,
        private AgentActors $identities,
        private AgentRunLog $runs,
        private ActorContextBinder $binder,
        private ToolGateway $tools,
        private Clock $clock,
    ) {}

    /** @return array{workspaces: int, rules: int, acted: int, refused: int} */
    public function __invoke(?string $workspaceId = null): array
    {
        $totals = ['workspaces' => 0, 'rules' => 0, 'acted' => 0, 'refused' => 0];

        foreach ($this->runs->workspaces($workspaceId) as $workspace) {
            $totals['workspaces']++;
            $run = null;

            try {
                $this->binder->bind(new UserId($this->identities->ensure($workspace)), new WorkspaceId($workspace));
                $run = $this->runs->start($workspace, 'automation');
                $done = ['rules' => 0, 'acted' => 0, 'refused' => []];

                foreach ($this->rules->visibleTo($workspace, null, activeOnly: true) as $rule) {
                    $done['rules']++;
                    $this->rule($rule, $done);
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

    /** @param array{rules: int, acted: int, refused: array<string, int>} $done */
    private function rule(RuleView $rule, array &$done): void
    {
        $today = $this->clock->now();
        $assessment = $this->campaigns->assessAll(new CampaignControlFilter(
            clientIds: $rule->clientId === null ? [] : [$rule->clientId],
            projectIds: $rule->projectId === null ? [] : [$rule->projectId],
        ), $rule->windowDays);

        $hits = [];

        foreach ($assessment['rows'] as $row) {
            $breach = RuleEvaluator::breach($rule, $row, $today);

            if ($breach !== null) {
                $hits[$row->campaignId] = [$row, $breach];
            }
        }

        $streaks = $this->rules->observe($rule->id, array_keys($hits), $today->format('Y-m-d'));

        if ($rule->action === RuleAction::Alert || ! $rule->trustLevel->allows($rule->action)) {
            return;
        }

        $acted = 0;

        foreach ($hits as $campaignId => [$row, $breach]) {
            $streak = $streaks[$campaignId] ?? ['days' => 0, 'acted' => true];

            if ($streak['acted'] || $streak['days'] < $rule->forDays || $acted >= self::ACTIONS_PER_RULE) {
                continue;
            }

            $reason = sprintf('Rule "%s": %s Held for %d day(s).', $rule->name, $breach[0], $streak['days']);

            $outcome = match ($rule->action) {
                RuleAction::ProposePause => $this->tools->call('propose_pause_campaign', ['campaign_id' => $campaignId, 'reason' => $reason], 'agent', 'automation'),
                RuleAction::ProposeBudgetCut => $row->budgetMinor === null || $row->budgetMinor <= 0
                    ? ['ok' => false, 'error' => ['code' => 'no_daily_budget', 'message' => 'No daily budget to lower.']]
                    : $this->tools->call('propose_budget_change', [
                        'campaign_id' => $campaignId,
                        'daily_budget_minor' => (int) round($row->budgetMinor * (100 - (int) $rule->actionPct) / 100),
                        'reason' => $reason,
                    ], 'agent', 'automation'),
                default => $this->tools->call('create_task', [
                    'title' => sprintf('%s — %s', $rule->name, $row->campaignName),
                    'description' => $reason,
                    ...($row->projectId === null ? [] : ['project_id' => $row->projectId]),
                ], 'agent', 'automation'),
            };

            /* Acted or refused, the streak is spent: a refusal (cooldown, limit, undocumented write) is not retried hourly. */
            $this->rules->markActed($rule->id, $campaignId);

            if ($outcome['ok']) {
                $acted++;
                $done['acted']++;
            } else {
                $code = (string) ($outcome['error']['code'] ?? 'failed');
                $done['refused'][$code] = ($done['refused'][$code] ?? 0) + 1;
            }
        }
    }
}
