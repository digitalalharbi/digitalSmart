<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Integration\Contract\SyncCandidateSource;
use App\Application\Shared\Contract\Clock;
use App\Domain\Integration\Sync\SyncPlanner;
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
        {--until= : End of the performance window (Y-m-d)}
        {--force : Ignore freshness and archived projects}';

    protected $description = 'Queue advertising syncs for every bound, sync-enabled external account';

    public function handle(SyncCandidateSource $source, SyncPlanner $planner, Clock $clock): int
    {
        $only = $this->stringOption('workspace');
        $candidates = $source->candidates($only === null ? null : new WorkspaceId($only));

        if ($candidates === []) {
            // Nothing bound with sync enabled. Not an error: discovering an
            // account grants nothing, and neither does connecting a platform.
            $this->components->warn('No bound accounts have sync enabled.');

            return self::SUCCESS;
        }

        $since = $this->stringOption('since');
        $plan = $planner->plan(
            $candidates,
            $clock->now(),
            /* Naming a window is asking for history, which is the lowest-priority work there is. */
            backfill: $since !== null || $this->stringOption('until') !== null,
            force: (bool) $this->option('force'),
        );

        foreach ($plan as $planned) {
            SyncBoundAccountJob::dispatch(
                $planned->candidate->workspaceId,
                $planned->candidate->externalAccountId,
                $since,
                $this->stringOption('until'),
                $planned->class->queue(),
                $planned->candidate->provider->value,
            )->delay($planned->delaySeconds > 0 ? now()->addSeconds($planned->delaySeconds) : null);
        }

        $this->components->info(sprintf(
            'Queued %d of %d account syncs (%d left alone: fresh, or every project finished).',
            count($plan),
            count($candidates),
            count($candidates) - count($plan),
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
