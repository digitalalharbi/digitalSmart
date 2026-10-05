<?php

declare(strict_types=1);

use App\Application\Advertising\Contract\AdvertisingSyncAdapter;
use App\Application\Advertising\Contract\SyncAdapterRegistry;
use App\Application\Integration\Contract\ProviderAdapter;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Infrastructure\Integration\ProviderAdapterRegistry;

/*
|--------------------------------------------------------------------------
| Integration boundaries
|--------------------------------------------------------------------------
|
| "No provider-specific logic in controllers or core domain models" is a rule
| worth enforcing rather than remembering.
|
*/

arch('provider adapters live only in infrastructure')
    ->expect('App\Infrastructure\Integration\Adapter')
    ->toImplement(ProviderAdapter::class);

arch('no adapter is referenced outside infrastructure and its registry')
    ->expect('App\Infrastructure\Integration\Adapter')
    ->toOnlyBeUsedIn([
        'App\Infrastructure\Integration',
        'App\Providers',
    ]);

arch('the domain knows nothing about HTTP clients')
    ->expect('App\Domain\Integration')
    ->not->toUse(['GuzzleHttp', 'Illuminate\Http', 'Illuminate\Support\Facades\Http']);

arch('integration use cases depend on the port, never a concrete adapter')
    ->expect('App\Application\Integration')
    ->not->toUse('App\Infrastructure');

arch('credentials never reach the interface layer directly')
    ->expect('App\Domain\Integration\Credentials')
    ->toOnlyBeUsedIn([
        'App\Domain\Integration',
        'App\Application\Integration',
        // Sync adapters authenticate with the same credentials; the sync use
        // case passes them straight through without reading them.
        'App\Application\Advertising',
        'App\Infrastructure\Integration',
        'App\Infrastructure\Advertising',
        'App\Infrastructure\Persistence\Eloquent\Repository',
    ]);

arch('sync adapters live only in infrastructure')
    ->expect('App\Infrastructure\Advertising\Adapter')
    ->toImplement(AdvertisingSyncAdapter::class);

arch('no sync adapter is referenced outside infrastructure and its registry')
    ->expect('App\Infrastructure\Advertising\Adapter')
    ->toOnlyBeUsedIn([
        'App\Infrastructure\Advertising',
        'App\Providers',
    ]);

arch('provider mappers never touch the database')
    ->expect('App\Infrastructure\Advertising\Mapper')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades']);

arch('advertising use cases depend on ports, never a concrete adapter')
    ->expect('App\Application\Advertising')
    ->not->toUse('App\Infrastructure');

it('reports honestly which providers it can sync', function (): void {
    $registry = app(SyncAdapterRegistry::class);

    foreach (Provider::cases() as $provider) {
        if (! $registry->supports($provider)) {
            // Absence must be an explicit refusal, not a stub that would
            // invent field names the provider does not publish.
            expect(fn (): mixed => $registry->for($provider))
                ->toThrow(ProviderUnavailable::class);

            continue;
        }

        expect($registry->for($provider))->toBeInstanceOf(AdvertisingSyncAdapter::class);
    }
});

it('never offers a sync for a provider whose platform lacks metrics', function (): void {
    $registry = app(SyncAdapterRegistry::class);

    foreach (Provider::cases() as $provider) {
        if ($registry->supports($provider)) {
            expect($provider->supports(ProviderCapability::MetricsSync))->toBeTrue(sprintf(
                '%s has a sync adapter but its platform does not publish a metrics API',
                $provider->value,
            ));
        }
    }
});

it('has an adapter for every provider', function (): void {
    $registry = app(ProviderAdapterRegistry::class);

    foreach (Provider::cases() as $provider) {
        // Adding a provider to the enum without an adapter should fail here,
        // not at runtime in front of a user.
        expect($registry->for($provider)->provider())->toBe($provider);
    }
});

it('never lets an adapter claim a capability its provider does not have', function (): void {
    $registry = app(ProviderAdapterRegistry::class);

    foreach ($registry->all() as $adapter) {
        $declared = $adapter->capabilities();
        $platform = $adapter->provider()->capabilities();

        foreach ($declared as $capability) {
            expect(in_array($capability, $platform, true))->toBeTrue(sprintf(
                '%s claims %s, which the platform does not support',
                $adapter->provider()->value,
                $capability->value,
            ));
        }
    }
});
