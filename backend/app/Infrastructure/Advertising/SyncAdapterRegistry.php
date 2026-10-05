<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising;

use App\Application\Advertising\Contract\AdvertisingSyncAdapter;
use App\Application\Advertising\Contract\SyncAdapterRegistry as Registry;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Adapter\GoogleAdsSyncAdapter;
use App\Infrastructure\Advertising\Adapter\MetaSyncAdapter;
use App\Infrastructure\Advertising\Adapter\TikTokSyncAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the sync adapter for a provider.
 *
 * Absence is meaningful here. A provider missing from this map has no sync
 * implementation, `supports()` says so, and the UI disables the action instead
 * of offering one that would fail — which is also why there is no fallback
 * adapter: a generic one could only guess at field names, and a guess rendered
 * as a metric is indistinguishable from a lie.
 */
final readonly class SyncAdapterRegistry implements Registry
{
    /** @var array<string, class-string<AdvertisingSyncAdapter>> */
    private const ADAPTERS = [
        Provider::Meta->value => MetaSyncAdapter::class,
        Provider::GoogleAds->value => GoogleAdsSyncAdapter::class,
        Provider::TikTok->value => TikTokSyncAdapter::class,
    ];

    public function __construct(private Container $container) {}

    public function supports(Provider $provider): bool
    {
        return isset(self::ADAPTERS[$provider->value]);
    }

    public function for(Provider $provider): AdvertisingSyncAdapter
    {
        $class = self::ADAPTERS[$provider->value] ?? null;

        if ($class === null) {
            throw ProviderUnavailable::noSyncAdapter($provider);
        }

        /** @var AdvertisingSyncAdapter $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }
}
