<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Automation\Command\RunAutomationRules;
use Illuminate\Console\Command;

/** Hourly: how long each rule has held, and — within each rule's trust — its proposal or task. Never a platform change. */
final class RunAutomationRulesCommand extends Command
{
    protected $signature = 'automation:run {--workspace= : Limit to one workspace id} {--force : Evaluate every active rule now, whatever its schedule}';

    protected $description = 'Evaluate automation rules, keep their streaks and raise their proposals or tasks';

    public function handle(RunAutomationRules $run): int
    {
        $workspace = $this->option('workspace');
        $result = $run(is_string($workspace) && $workspace !== '' ? $workspace : null, (bool) $this->option('force'));

        $this->components->info(sprintf('%d rule(s) in %d workspace(s); %d action(s) raised, %d refused.', $result['rules'], $result['workspaces'], $result['acted'], $result['refused']));

        return self::SUCCESS;
    }
}
