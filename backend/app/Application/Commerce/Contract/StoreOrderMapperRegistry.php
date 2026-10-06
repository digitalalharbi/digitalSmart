<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Domain\Integration\Provider;

/**
 * Resolves the thing that turns one platform's order payload into a normalised order.
 *
 * Needed because the webhook worker has a payload and a provider and no adapter: it is not reading from
 * an API, it is applying something that arrived. Exposed as a port so the worker does not reach into
 * Infrastructure for a mapper, and so a provider without one fails loudly rather than being guessed at.
 */
interface StoreOrderMapperRegistry
{
    public function for(Provider $provider): StoreOrderMapper;
}
