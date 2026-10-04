<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests get the full application and a transactional database.
| Unit tests get neither: they must exercise plain objects, which keeps the
| Application and Domain layers honest about their dependencies.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        /*
         * Feature tests address the API from the SPA's own origin. Sanctum only
         * applies the stateful session middleware to requests it recognises as
         * first-party, so without this the suite would exercise a token-style
         * path the browser never takes.
         */
        $origin = (string) config('app.frontend_url');

        if ($origin !== '') {
            $this->withHeader('Origin', $origin);
        }
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Architecture');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** Build a provisioned workspace with its system roles and an owner. */
function workspaceScenario(?string $name = null): Tests\Support\WorkspaceScenario
{
    return Tests\Support\WorkspaceScenario::create($name);
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeUuid', function (): mixed {
    expect($this->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');

    return $this;
});
