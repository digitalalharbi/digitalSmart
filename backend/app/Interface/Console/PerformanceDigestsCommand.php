<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Digest\Command\BuildPerformanceDigests;
use App\Domain\Attention\DigestKind;
use Illuminate\Console\Command;

/**
 * `digests:performance` with no argument is what the scheduler runs hourly: each workspace gets whichever digests
 * are due at its own local nine. With a kind, it builds that digest now, for every workspace or one — the
 * operator's hand, and the tests'.
 */
final class PerformanceDigestsCommand extends Command
{
    protected $signature = 'digests:performance {kind? : daily_performance | weekly_performance | monthly_performance} {--workspace= : Limit to one workspace id}';

    protected $description = 'Build the performance digests that are due in each workspace\'s own time zone, or one kind now';

    public function handle(BuildPerformanceDigests $build): int
    {
        $workspace = $this->option('workspace');
        $workspaceId = is_string($workspace) && $workspace !== '' ? $workspace : null;
        $argument = $this->argument('kind');

        if ($argument === null) {
            foreach ($build->dispatch($workspaceId) as $kind => $result) {
                $this->components->info(sprintf('%s: %d written, %d with nothing to say, %d failed.', $kind, $result['written'], $result['skipped'], $result['failed']));
            }

            return self::SUCCESS;
        }

        $kind = is_string($argument) ? DigestKind::tryFrom($argument) : null;

        if ($kind === null || ! $kind->isPerformance()) {
            $this->components->error('Unknown performance digest. Expected: daily_performance, weekly_performance, monthly_performance.');

            return self::FAILURE;
        }

        $result = $build->build($kind, $workspaceId);
        $this->components->info(sprintf('%s: %d written, %d with nothing to say, %d failed.', $kind->label(), $result['written'], $result['skipped'], $result['failed']));

        return self::SUCCESS;
    }
}
