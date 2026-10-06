<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Domain\Integration\Provider;

/**
 * Resolves the commerce adapter for a provider.
 *
 * Absence is meaningful, as in every other registry here: a provider with no implementation says so,
 * and the interface disables the action rather than offering one that fails. There is no fallback,
 * because a generic store adapter could only guess at field names — and a guessed order total is a
 * client's revenue, reported wrongly, with nothing to reveal it.
 */
interface CommerceSyncAdapterRegistry
{
    public function supports(Provider $provider): bool;

    public function for(Provider $provider): CommerceSyncAdapter;
}
