<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Agents\Agent\AgentOrchestrator;
use App\Application\Agents\Contract\AgentRunLog;
use Illuminate\Console\Command;

/** The routines: look, compare to each project's own targets, propose. Nothing here changes a platform. */
final class RunAgentsCommand extends Command
{
    protected $signature = 'agents:run {which=all : all | analyst | finance} {--workspace= : Limit to one workspace id}';

    protected $description = 'Run the analyst, campaign and finance agents; they propose, people decide';

    public function handle(AgentOrchestrator $agents, AgentRunLog $runs): int
    {
        $which = $this->argument('which');

        if (! in_array($which, ['all', 'analyst', 'finance'], true)) {
            $this->components->error('Unknown agent. Expected: all, analyst, finance.');

            return self::FAILURE;
        }

        $workspace = $this->option('workspace');
        $result = $agents->run($which, is_string($workspace) && $workspace !== '' ? $workspace : null);

        $this->components->info(sprintf('%d workspace(s) looked at, %d proposal(s) raised, %d failed.', $result['workspaces'], $result['proposed'], $result['failed']));

        $expired = $runs->expireProposals();

        if ($expired > 0) {
            $this->components->info(sprintf('%d proposal(s) expired.', $expired));
        }

        return self::SUCCESS;
    }
}
