<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

use App\Domain\Shared\ValueObject\ServiceStatus;

/** Result of the system health use case. */
final readonly class SystemHealthReport
{
    /** @param list<ServiceHealth> $services */
    public function __construct(
        public string $application,
        public string $version,
        public string $environment,
        public array $services,
    ) {}

    /** Overall status is the worst individual status. */
    public function status(): ServiceStatus
    {
        return ServiceStatus::worst(
            ...array_map(static fn (ServiceHealth $service): ServiceStatus => $service->status, $this->services),
        );
    }
}
