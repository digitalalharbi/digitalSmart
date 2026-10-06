<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Domain\Integration\Provider;

/**
 * Resolves the measurement adapter for a provider.
 *
 * Absence is meaningful, as in the advertising registry: a provider with no implementation
 * says so, and the interface disables the action rather than offering one that would fail.
 * There is no fallback adapter, because a generic one could only guess at field names and a
 * guess rendered as a conversion count is indistinguishable from a lie.
 */
interface MeasurementSyncAdapterRegistry
{
    public function supports(Provider $provider): bool;

    public function for(Provider $provider): MeasurementSyncAdapter;
}
