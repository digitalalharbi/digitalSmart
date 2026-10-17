<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Digest\Command\BuildDigests;
use App\Domain\Attention\DigestKind;
use Illuminate\Console\Command;

final class BuildDigestsCommand extends Command
{
    protected $signature = 'digests:build {kind : morning_campaigns | daily_budget | weekly_projects | monthly_executive} {--workspace= : Limit to one workspace id}';

    protected $description = 'Write each person one role-aware summary instead of many alerts';

    public function handle(BuildDigests $build): int
    {
        $argument = $this->argument('kind');
        $kind = is_string($argument) ? DigestKind::tryFrom($argument) : null;

        if ($kind === null) {
            $this->components->error('Unknown digest. Expected: '.implode(', ', DigestKind::values()));

            return self::FAILURE;
        }

        $workspace = $this->option('workspace');
        $result = $build($kind, is_string($workspace) && $workspace !== '' ? $workspace : null);

        $this->components->info(sprintf(
            '%s: %d written, %d with nothing to say, %d failed.',
            $kind->label(), $result['written'], $result['skipped'], $result['failed'],
        ));

        return self::SUCCESS;
    }
}
