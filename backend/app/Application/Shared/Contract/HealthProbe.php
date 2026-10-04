<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Application\Shared\DTO\ServiceHealth;

/**
 * Outbound port: one probe per infrastructure dependency.
 *
 * Implementations live in Infrastructure, so the use case can be tested without
 * a database, cache or queue.
 */
interface HealthProbe
{
    /** Probe the dependency. Implementations must not throw. */
    public function probe(): ServiceHealth;
}
