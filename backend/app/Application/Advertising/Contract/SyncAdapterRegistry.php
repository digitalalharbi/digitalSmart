<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Domain\Integration\Provider;

interface SyncAdapterRegistry
{
    /**
     * Whether a sync adapter exists for this provider.
     *
     * False is a real answer, not a gap to be filled with a stub: ChatGPT Ads
     * publishes no reporting contract, so there is nothing to implement, and the
     * UI must be able to say so rather than offer a button that cannot work.
     */
    public function supports(Provider $provider): bool;

    /** @throws \App\Application\Integration\Exception\ProviderUnavailable when unsupported */
    public function for(Provider $provider): AdvertisingSyncAdapter;
}
