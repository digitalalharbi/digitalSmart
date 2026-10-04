<?php

declare(strict_types=1);

namespace App\Application\System\Query;

use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\SystemHealthReport;

/**
 * Use case: report whether the platform's dependencies are serving traffic.
 *
 * Probes are injected, so this class holds the orchestration only — no I/O.
 */
final readonly class GetSystemHealth
{
    /** @param iterable<HealthProbe> $probes */
    public function __construct(
        private ApplicationInfo $info,
        private iterable $probes,
    ) {}

    public function __invoke(): SystemHealthReport
    {
        $services = [];

        foreach ($this->probes as $probe) {
            $services[] = $probe->probe();
        }

        return new SystemHealthReport(
            application: $this->info->name(),
            version: $this->info->version(),
            environment: $this->info->environment(),
            services: $services,
        );
    }
}
