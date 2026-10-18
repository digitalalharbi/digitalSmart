<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\Contract\AgentActors;
use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Runs the agents for a workspace, in order, as the workspace's agent identity.
 *
 * The Orchestrator coordinates and never changes a campaign itself: the Analyst reads, the Campaign agent proposes,
 * the Finance agent opens a task. Each stage is recorded as a run a person can see (analysing → preparing → waiting
 * for approval → completed), and one stage failing is recorded and does not stop the next workspace.
 *
 * Nothing here calls a language model. The numbers are deterministic, the rules are the project's own, and a model
 * would add prose to a finding rather than a finding — that belongs to the assistant, behind its own guard.
 */
final readonly class AgentOrchestrator
{
    public function __construct(
        private AgentActors $identities,
        private AgentRunLog $runs,
        private ActorContextBinder $actors,
        private ToolGateway $tools,
        private AnalystAgent $analyst,
        private CampaignAgent $campaign,
        private FinanceAgent $finance,
    ) {}

    /**
     * @param  'all'|'analyst'|'finance'  $which
     * @return array{workspaces: int, proposed: int, failed: int}
     */
    public function run(string $which = 'all', ?string $workspaceId = null): array
    {
        $totals = ['workspaces' => 0, 'proposed' => 0, 'failed' => 0];

        foreach ($this->runs->workspaces($workspaceId) as $workspace) {
            $totals['workspaces']++;

            try {
                $agent = $this->identities->ensure($workspace);
                $this->actors->bind(new UserId($agent), new WorkspaceId($workspace));

                if ($which === 'all' || $which === 'analyst') {
                    $totals['proposed'] += $this->analysis($workspace);
                }

                if ($which === 'all' || $which === 'finance') {
                    $this->budgets($workspace);
                }
            } catch (Throwable $failure) {
                report($failure);
                $totals['failed']++;
            } finally {
                $this->actors->clear();
            }
        }

        return $totals;
    }

    private function analysis(string $workspace): int
    {
        $run = $this->runs->start($workspace, 'analyst');

        try {
            $seen = $this->analyst->analyse($this->tools, 'analyst');
            $this->runs->progress($run, 'preparing', $seen['looked_at'], 0, [
                'findings' => count($seen['findings']),
                'without_target' => $seen['without_target'],
            ]);

            $made = $this->campaign->act($seen['findings'], $this->tools, 'campaign');
            $this->runs->progress($run, $made['proposed'] > 0 ? 'waiting_approval' : 'completed', $seen['looked_at'], $made['proposed'], [
                'findings' => count($seen['findings']),
                'without_target' => $seen['without_target'],
                'proposals' => $made['by_tool'],
                'tasks' => $made['tasks'],
                'refused' => $made['refused'],
            ]);

            return $made['proposed'];
        } catch (Throwable $failure) {
            $this->runs->fail($run, 'The run could not finish.');

            throw $failure;
        }
    }

    private function budgets(string $workspace): void
    {
        $run = $this->runs->start($workspace, 'finance');

        try {
            $result = $this->finance->act($this->tools, 'finance');
            $this->runs->progress($run, 'completed', $result['looked_at'], 0, ['tasks' => $result['tasks']]);
        } catch (Throwable $failure) {
            $this->runs->fail($run, 'The run could not finish.');

            throw $failure;
        }
    }
}
