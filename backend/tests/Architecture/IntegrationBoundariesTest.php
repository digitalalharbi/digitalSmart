<?php

declare(strict_types=1);

use App\Application\Integration\Contract\ProviderAdapter;
use App\Domain\Integration\Provider;
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
        'App\Infrastructure\Integration',
        'App\Infrastructure\Persistence\Eloquent\Repository',
    ]);

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
