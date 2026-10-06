<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Measurement\Contract\MeasurementSyncAdapter;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry as Registry;
use App\Domain\Integration\Provider;
use App\Infrastructure\Measurement\Adapter\GoogleAnalyticsSyncAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Which providers can be read for measurement.
 *
 * One entry today. The commerce providers report orders rather than events and arrive with
 * their own domain in a later phase; Tag Manager reports configuration, which is a readiness
 * question rather than a measurement one and has no counts to contribute at all.
 */
final readonly class MeasurementSyncAdapterRegistry implements Registry
{
    /** @var array<string, class-string<MeasurementSyncAdapter>> */
    private const array ADAPTERS = [
        Provider::GoogleAnalytics->value => GoogleAnalyticsSyncAdapter::class,
    ];

    public function __construct(private Container $container) {}

    public function supports(Provider $provider): bool
    {
        return isset(self::ADAPTERS[$provider->value]);
    }

    public function for(Provider $provider): MeasurementSyncAdapter
    {
        $class = self::ADAPTERS[$provider->value] ?? null;

        if ($class === null) {
            throw ProviderUnavailable::noSyncAdapter($provider);
        }

        /** @var MeasurementSyncAdapter $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }
}
