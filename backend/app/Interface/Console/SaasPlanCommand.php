<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Saas\Command\ChangeWorkspacePlan;
use App\Application\Saas\Exception\EntitlementChangeRefused;
use Illuminate\Console\Command;

/**
 * Put a workspace on a plan, from the operator's side. Until the platform console exists this is the only way in, and it asks for
 * the same two things the console will: who is doing it and why.
 */
final class SaasPlanCommand extends Command
{
    protected $signature = 'saas:plan
        {workspace : The workspace id}
        {plan : starter, growth, agency or enterprise}
        {--operator= : Who is making the change}
        {--reason= : Why}';

    protected $description = 'Assign a plan to a workspace (data is never removed)';

    public function handle(ChangeWorkspacePlan $change): int
    {
        try {
            $change(
                $this->text($this->argument('workspace')),
                $this->text($this->argument('plan')),
                $this->text($this->option('operator')),
                $this->text($this->option('reason')),
            );
        } catch (EntitlementChangeRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Plan assigned. Nothing the workspace already holds was removed.');

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
