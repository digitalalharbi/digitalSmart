<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\Contract\CommerceSyncAdapter;
use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry as Registry;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Domain\Integration\Provider;
use App\Infrastructure\Commerce\Adapter\SallaSyncAdapter;
use Illuminate\Contracts\Container\Container;

/** Which store platforms this product can read. Zid joins in its own phase. */
final readonly class CommerceSyncAdapterRegistry implements Registry
{
    /** @var array<string, class-string<CommerceSyncAdapter>> */
    private const array ADAPTERS = [
        Provider::Salla->value => SallaSyncAdapter::class,
    ];

    public function __construct(private Container $container) {}

    public function supports(Provider $provider): bool
    {
        return isset(self::ADAPTERS[$provider->value]);
    }

    public function for(Provider $provider): CommerceSyncAdapter
    {
        $class = self::ADAPTERS[$provider->value] ?? null;

        if ($class === null) {
            throw ProviderUnavailable::noSyncAdapter($provider);
        }

        /** @var CommerceSyncAdapter $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }
}
