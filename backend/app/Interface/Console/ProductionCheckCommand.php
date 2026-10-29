<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\System\Contract\ProductionReadiness;
use App\Application\System\Readiness;
use Illuminate\Console\Command;

/**
 * Is this server's configuration fit to serve real users? Read-only; run it on the server before the first request and after every
 * change to its environment. Exit 1 on any failure (and on any warning with `--strict`), so a pipeline can gate on it.
 */
final class ProductionCheckCommand extends Command
{
    protected $signature = 'ops:production-check
        {--expect-env=production : The environment this server is meant to run (production or staging)}
        {--skip-connect : Check configuration only; do not touch the database or Redis}
        {--strict : Treat warnings as failures}
        {--json : Print the report as JSON}';

    protected $description = 'Check this server\'s configuration for production use (read-only)';

    public function handle(ProductionReadiness $readiness): int
    {
        $expected = $this->option('expect-env');
        $checks = $readiness->check(is_string($expected) && $expected !== '' ? $expected : 'production', ! (bool) $this->option('skip-connect'));
        $overall = Readiness::overall($checks, (bool) $this->option('strict'));
        $plain = Readiness::overall($checks);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['status' => $plain, 'checks' => $checks], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $check) {
                $line = sprintf('%-22s %s', $check['key'], $check['detail']);

                match ($check['status']) {
                    Readiness::PASS => $this->components->twoColumnDetail($check['key'], '<fg=green>PASS</>'),
                    Readiness::WARN => $this->components->warn($line),
                    default => $this->components->error($line),
                };
            }

            $this->newLine();
            $this->line(sprintf('Result: %s. Nothing was changed. A pass here is configuration only — it is not a deployment, and it says nothing about live providers.', strtoupper($plain)));
        }

        return $overall === Readiness::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
