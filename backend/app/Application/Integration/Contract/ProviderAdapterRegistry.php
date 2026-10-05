<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Domain\Integration\Provider;

interface ProviderAdapterRegistry
{
    public function for(Provider $provider): ProviderAdapter;

    /** @return list<ProviderAdapter> */
    public function all(): array;
}
