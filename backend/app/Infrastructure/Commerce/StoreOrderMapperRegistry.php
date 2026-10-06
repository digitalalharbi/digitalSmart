<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\Contract\StoreOrderMapper;
use App\Application\Commerce\Contract\StoreOrderMapperRegistry as Registry;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Domain\Integration\Provider;
use App\Infrastructure\Commerce\Mapper\SallaMapper;
use Illuminate\Contracts\Container\Container;

/** The same mappers the sync adapters use, reachable by provider for the webhook worker. */
final readonly class StoreOrderMapperRegistry implements Registry
{
    /** @var array<string, class-string<StoreOrderMapper>> */
    private const array MAPPERS = [
        Provider::Salla->value => SallaMapper::class,
    ];

    public function __construct(private Container $container) {}

    public function for(Provider $provider): StoreOrderMapper
    {
        $class = self::MAPPERS[$provider->value] ?? null;

        if ($class === null) {
            // No mapper means no honest way to read the payload, and a guess would be a revenue figure.
            throw ProviderUnavailable::noSyncAdapter($provider);
        }

        /** @var StoreOrderMapper $mapper */
        $mapper = $this->container->make($class);

        return $mapper;
    }
}
