<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Saas\Command\OverrideEntitlement;
use App\Application\Saas\Exception\EntitlementChangeRefused;
use Illuminate\Console\Command;

/** Change one feature or one limit for one workspace, above its plan; `inherit` hands it back to the plan. */
final class SaasOverrideCommand extends Command
{
    protected $signature = 'saas:override
        {workspace : The workspace id}
        {kind : feature or limit}
        {key : The feature or limit key}
        {value : on|off|inherit for a feature; a number, unlimited or inherit for a limit}
        {--operator= : Who is making the change}
        {--reason= : Why}';

    protected $description = 'Override one feature or limit for a workspace';

    public function handle(OverrideEntitlement $override): int
    {
        try {
            $previous = $override(
                $this->text($this->argument('workspace')),
                $this->text($this->argument('kind')),
                $this->text($this->argument('key')),
                $this->text($this->argument('value')),
                $this->text($this->option('operator')),
                $this->text($this->option('reason')),
            );
        } catch (EntitlementChangeRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Recorded. It was %s.', $previous));

        return self::SUCCESS;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
