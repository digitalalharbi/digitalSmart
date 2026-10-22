<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Notifications\Command\RaisePerformanceAlerts;
use Illuminate\Console\Command;

final class RaiseAlertsCommand extends Command
{
    protected $signature = 'alerts:raise {--workspace= : Limit to one workspace id}';

    protected $description = 'Turn the product\'s own signals into the bell\'s alerts, once each';

    public function handle(RaisePerformanceAlerts $raise): int
    {
        $workspace = $this->option('workspace');
        $result = $raise(is_string($workspace) && $workspace !== '' ? $workspace : null);

        $this->components->info(sprintf('%d alerts raised across %d workspaces, %d failed.', $result['raised'], $result['workspaces'], $result['failed']));

        return self::SUCCESS;
    }
}
