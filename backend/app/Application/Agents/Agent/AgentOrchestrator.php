<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\Contract\AgentActors;
use App\Application\Agents\Contract\AgentRunLog;
use App\Application\Agents\Contract\FindingLog;
use App\Application\Agents\Tool\ToolGateway;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Runs the agents for a workspace, as the workspace's agent identity, and combines what they found.
 *
 * Order matters and is fixed: the Analyst reads and the Campaign agent proposes first, so the Creative agent knows
 * which campaigns are about to be proposed for pausing — a pause outranks a creative refresh on the same campaign,
 * and that conflict is resolved here, in code, not left for two cards to contradict each other on Today. Finance,
 * Leads, Reports and Project operations follow; Project operations last, so it groups what the others raised.
 *
 * The Orchestrator never changes a campaign itself and no agent reaches a provider: every read and write goes through
 * the tool gateway in the agent's own name, and the agent's role cannot decide or carry out anything. Each agent is a
 * recorded run a person can see; one failing is recorded and does not stop the next. The caller's own identity is
 * put back afterwards, so a person asking from a screen never continues as the agent.
 *
 * Nothing here calls a language model: the numbers are deterministic and the thresholds are stated in each agent.
 */
final readonly class AgentOrchestrator
{
    public function __construct(
        private AgentActors $identities,
        private AgentRunLog $runs,
        private ActorContextBinder $binder,
        private ActorContext $actor,
        private ToolGateway $tools,
        private AnalystAgent $analyst,
        private CampaignAgent $campaign,
        private FinanceAgent $finance,
        private CreativeAgent $creative,
        private LeadsAgent $leads,
        private ReportsAgent $reports,
        private ProjectOperationsAgent $operations,
        private FindingLog $findings,
        private TrackingReview $tracking,
    ) {}

    /**
     * @param  'all'|list<string>|string  $which
     * @return array{workspaces: int, proposed: int, findings: int, failed: int}
     */
    public function run(string|array $which = 'all', ?string $workspaceId = null): array
    {
        $agents = $which === 'all' ? AgentRouter::AGENTS : (is_array($which) ? $which : [$which]);
        $totals = ['workspaces' => 0, 'proposed' => 0, 'findings' => 0, 'failed' => 0];
        $caller = $this->actor->isBound() ? [$this->actor->userId(), $this->actor->workspaceId()] : null;

        foreach ($this->runs->workspaces($workspaceId) as $workspace) {
            $totals['workspaces']++;

            try {
                $agent = $this->identities->ensure($workspace);
                $this->binder->bind(new UserId($agent), new WorkspaceId($workspace));
                $pausing = [];

                if (in_array('analyst', $agents, true)) {
                    [$proposed, $pausing] = $this->analysis($workspace);
                    $totals['proposed'] += $proposed;
                }

                if (in_array('tracking', $agents, true)) {
                    $this->stage($workspace, 'tracking', fn (): array => $this->tracking->act());
                }

                if (in_array('finance', $agents, true)) {
                    $this->stage($workspace, 'finance', fn (): array => $this->finance->act($this->tools, 'finance'));
                }

                foreach (['creative', 'leads', 'reports', 'operations'] as $name) {
                    if (in_array($name, $agents, true)) {
                        $totals['findings'] += $this->specialist($workspace, $name, $pausing);
                    }
                }
            } catch (Throwable $failure) {
                report($failure);
                $totals['failed']++;
            } finally {
                $this->binder->clear();
            }
        }

        if ($caller !== null) {
            $this->binder->bind($caller[0], $caller[1]);
        }

        return $totals;
    }

    /** @return array{0: int, 1: list<string>} */
    private function analysis(string $workspace): array
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

            return [$made['proposed'], $made['pausing']];
        } catch (Throwable $failure) {
            $this->runs->fail($run, 'The run could not finish.');

            throw $failure;
        }
    }

    /** @param list<string> $pausing */
    private function specialist(string $workspace, string $name, array $pausing): int
    {
        $result = $this->stage($workspace, $name, fn (): array => match ($name) {
            'creative' => $this->creative->act($this->tools, 'creative', $pausing),
            'leads' => $this->leads->act($this->tools, 'leads'),
            'reports' => $this->reports->act($this->tools, 'reports'),
            default => $this->operations->act($this->tools, 'operations'),
        }, store: $name);

        return count($result['findings'] ?? []);
    }

    /**
     * One recorded run around one agent.
     *
     * @param  callable(): array<string, mixed>  $work
     * @param  string|null  $store  Where the agent's findings are kept, for a specialist.
     * @return array<string, mixed>
     */
    private function stage(string $workspace, string $name, callable $work, ?string $store = null): array
    {
        $run = $this->runs->start($workspace, $name);

        try {
            $result = $work();
            $summary = array_filter($result, static fn (mixed $v, string $k): bool => $k !== 'findings' && $k !== 'looked_at', ARRAY_FILTER_USE_BOTH);

            if ($store !== null) {
                /** @var list<\App\Application\Agents\DTO\AgentFinding> $found */
                $found = $result['findings'] ?? [];
                $summary = [...$summary, 'findings' => count($found), ...$this->findings->observe($workspace, $store, $found)];
            }

            $this->runs->progress($run, 'completed', (int) ($result['looked_at'] ?? 0), 0, $summary);

            return $result;
        } catch (Throwable $failure) {
            $this->runs->fail($run, 'The run could not finish.');

            throw $failure;
        }
    }
}
