<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture tests
|--------------------------------------------------------------------------
|
| The layering rules in docs/ARCHITECTURE.md are executable, not advisory.
| Dependencies point inwards: Domain ← Application ← Infrastructure/Interface.
|
*/

arch('domain is framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Laravel',
        'Symfony',
        'App\Application',
        'App\Infrastructure',
        'App\Interface',
        'App\Providers',
    ]);

arch('application depends only on domain')
    ->expect('App\Application')
    ->not->toUse([
        'App\Infrastructure',
        'App\Interface',
        'App\Providers',
        'Illuminate\Http',
        'Illuminate\Database\Eloquent',
    ]);

arch('interface never reaches into infrastructure')
    ->expect('App\Interface')
    ->not->toUse(['App\Infrastructure']);

arch('nothing depends on the interface layer except the framework bootstrap')
    ->expect('App\Interface')
    ->not->toBeUsedIn([
        'App\Domain',
        'App\Application',
        'App\Infrastructure',
    ]);

arch('eloquent models stay inside the persistence layer')
    ->expect('Illuminate\Database\Eloquent\Model')
    ->toOnlyBeUsedIn([
        'App\Infrastructure\Persistence',
        'App\Providers',
    ]);

arch('controllers are thin and final')
    ->expect('App\Interface\Http\Controller')
    ->toBeClasses();

arch('use cases are invokable single-purpose objects')
    ->expect('App\Application\System\Query')
    ->toBeFinal()
    ->toBeReadonly();

arch('value objects and DTOs are immutable')
    ->expect('App\Application\Shared\DTO')
    ->toBeFinal()
    ->toBeReadonly();

arch('no debugging helpers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'exit', 'die'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('no facades inside the application layer')
    ->expect('Illuminate\Support\Facades')
    ->not->toBeUsedIn(['App\Domain', 'App\Application']);

/*
 * Rule 73, held by prohibition rather than by promise: no code reaches the shared cache directly. Every cache
 * key in a multi-tenant product has to carry the workspace, and the way to guarantee that is for there to be
 * exactly one place a key is built. None exists yet — nothing in this product caches tenant data — so the
 * facade is closed off, and the first thing that needs a cache has to write that one place (and be reviewed for
 * the workspace in its key) rather than call `Cache::remember('campaigns', …)` and serve one agency's numbers
 * to another.
 *
 * The rate limiter is not exempt by accident: it keys on a provider or an account id, never on tenant data,
 * and it is a different facade.
 */
arch('nothing caches tenant data through the shared cache directly')
    ->expect('Illuminate\Support\Facades\Cache')
    ->not->toBeUsedIn([
        'App\Domain',
        'App\Application',
        'App\Infrastructure',
        'App\Interface',
    ]);
