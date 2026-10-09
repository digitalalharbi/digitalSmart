<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Commerce\Contract\StoreDirectory;
use App\Domain\Workspace\WorkspaceId;
use App\Interface\Queue\ReadStoreJob;
use Illuminate\Console\Command;

/**
 * Queue a read for every connected store.
 *
 * The reconciliation pass, and the reason `SyncStore` exists: webhooks are faster and they are not sufficient.
 * One dropped delivery, one deploy during a busy hour, one platform incident, and a client's revenue is quietly
 * short for that window with nothing on any screen to indicate it. This is what makes the figures eventually
 * correct regardless.
 *
 * Dispatches rather than reading inline, so one slow platform cannot stall the others and each store gets the
 * job's own uniqueness, retries and backoff.
 */
final class ReadStores extends Command
{
    protected $signature = 'commerce:sync
        {--workspace= : Limit to one workspace id}
        {--since= : Read orders updated since this date (Y-m-d) instead of the provider\'s restatement window}';

    protected $description = 'Queue a reconciliation read for every connected store';

    public function handle(StoreDirectory $stores): int
    {
        $only = $this->stringOption('workspace');

        /*
         * The directory returns the targets with their workspace attached, which is what keeps this command out
         * of the persistence layer: enumerating workspaces here would mean reaching for an Eloquent model from
         * the interface layer, and the architecture test is right to refuse it.
         */
        $targets = $stores->readTargets($only === null ? null : new WorkspaceId($only));

        if ($targets === []) {
            $this->components->warn('No connected store can be read.');

            return self::SUCCESS;
        }

        foreach ($targets as $target) {
            ReadStoreJob::dispatch(
                $target->workspaceId->value,
                $target->storeId,
                $this->stringOption('since'),
            );
        }

        $this->components->info(sprintf(
            'Queued %d store %s.',
            count($targets),
            count($targets) === 1 ? 'read' : 'reads',
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
