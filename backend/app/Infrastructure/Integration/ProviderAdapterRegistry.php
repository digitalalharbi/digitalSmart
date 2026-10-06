<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration;

use App\Application\Integration\Contract\ProviderAdapter;
use App\Application\Integration\Contract\ProviderAdapterRegistry as Registry;
use App\Domain\Integration\Provider;
use App\Infrastructure\Integration\Adapter\GoogleAdsAdapter;
use App\Infrastructure\Integration\Adapter\GoogleAnalyticsAdapter;
use App\Infrastructure\Integration\Adapter\GoogleTagManagerAdapter;
use App\Infrastructure\Integration\Adapter\LinkedInAdsAdapter;
use App\Infrastructure\Integration\Adapter\MetaAdsAdapter;
use App\Infrastructure\Integration\Adapter\OpenAIAdsAdapter;
use App\Infrastructure\Integration\Adapter\SallaAdapter;
use App\Infrastructure\Integration\Adapter\SnapchatAdsAdapter;
use App\Infrastructure\Integration\Adapter\TikTokAdsAdapter;
use App\Infrastructure\Integration\Adapter\XAdsAdapter;
use App\Infrastructure\Integration\Adapter\ZidAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the adapter for a provider.
 *
 * Every provider in the enum has an entry, enforced by a test — so adding a
 * platform to the enum without an adapter fails the build rather than throwing
 * at runtime in front of a user.
 */
final readonly class ProviderAdapterRegistry implements Registry
{
    /** @var array<string, class-string<ProviderAdapter>> */
    private const ADAPTERS = [
        Provider::Meta->value => MetaAdsAdapter::class,
        Provider::GoogleAds->value => GoogleAdsAdapter::class,
        Provider::TikTok->value => TikTokAdsAdapter::class,
        Provider::Snapchat->value => SnapchatAdsAdapter::class,
        Provider::X->value => XAdsAdapter::class,
        Provider::LinkedIn->value => LinkedInAdsAdapter::class,
        Provider::OpenAI->value => OpenAIAdsAdapter::class,
        Provider::GoogleAnalytics->value => GoogleAnalyticsAdapter::class,
        Provider::GoogleTagManager->value => GoogleTagManagerAdapter::class,
        Provider::Salla->value => SallaAdapter::class,
        Provider::Zid->value => ZidAdapter::class,
    ];

    public function __construct(private Container $container) {}

    public function for(Provider $provider): ProviderAdapter
    {
        $class = self::ADAPTERS[$provider->value];

        /** @var ProviderAdapter $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }

    /** @return list<ProviderAdapter> */
    public function all(): array
    {
        return array_map(fn (Provider $provider): ProviderAdapter => $this->for($provider), Provider::cases());
    }
}
