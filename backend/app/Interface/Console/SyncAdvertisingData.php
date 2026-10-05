<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Workspace\WorkspaceId;
use App\Interface\Queue\SyncBoundAccountJob;
use Illuminate\Console\Command;

/**
 * Queue a sync for every account a project actually syncs.
 *
 * Dispatches rather than running inline, so one slow provider cannot stall the
 * others and each account gets the job's own retry and rate-limit handling.
 */
final class SyncAdvertisingData extends Command
{
    protected $signature = 'advertising:sync
        {--workspace= : Limit to one workspace id}
        {--since= : Start of the performance window (Y-m-d)}
        {--until= : End of the performance window (Y-m-d)}';

    protected $description = 'Queue advertising syncs for every bound, sync-enabled external account';

    public function handle(IntegrationBindingRepository $bindings): int
    {
        $only = $this->stringOption('workspace');
        $targets = $bindings->syncTargets($only === null ? null : new WorkspaceId($only));

        if ($targets === []) {
            // Nothing bound with sync enabled. Not an error: discovering an
            // account grants nothing, and neither does connecting a platform.
            $this->components->warn('No bound accounts have sync enabled.');

            return self::SUCCESS;
        }

        foreach ($targets as $target) {
            SyncBoundAccountJob::dispatch(
                $target->workspaceId->value,
                $target->externalAccountId->value,
                $this->stringOption('since'),
                $this->stringOption('until'),
            );
        }

        $this->components->info(sprintf(
            'Queued %d account %s.',
            count($targets),
            count($targets) === 1 ? 'sync' : 'syncs',
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
