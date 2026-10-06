<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| Cross-origin preflights
|--------------------------------------------------------------------------
|
| An API that does not answer a preflight looks, to a browser, exactly like an API that is
| down. The browser reports "preflight response is not successful", the application reports
| that it could not reach the server, and nothing in either message mentions CORS.
|
| That is not a theoretical concern here: it is how an intermittent end-to-end failure
| presented for weeks. WebKit issues a preflight for a request it considers cross-origin —
| which includes one dispatched from a document that is being torn down by a navigation, where
| the origin becomes opaque — and an unanswered OPTIONS fell through to routing and returned a
| 404, because no route declares that method.
|
| So the preflight is tested, for the first-party origin and for a stranger, and the answer in
| both cases is a *response* rather than a 404.
|
*/

beforeEach(function (): void {
    // The origin the SPA is served from in development, and the one the suite uses.
    Config::set('cors.allowed_origins', ['http://127.0.0.1:5174']);
});

/**
 * A preflight is an OPTIONS request carrying the method and headers the real request will use.
 *
 * @param  array<string, string>  $headers
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function preflight(string $path, array $headers = []): Illuminate\Testing\TestResponse
{
    return test()->call('OPTIONS', $path, [], [], [], [
        'HTTP_ORIGIN' => 'http://127.0.0.1:5174',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ...$headers,
    ]);
}

describe('a preflight', function (): void {
    it('is answered for an API route, rather than falling through to a 404', function (): void {
        $response = preflight('/api/auth/login');

        /*
         * 204 and the allow headers. A 404 here is the failure this test exists for: the browser
         * blocks the request that follows, the application says the server is unreachable, and
         * nothing says the word CORS.
         */
        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5174')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        expect($response->headers->get('Access-Control-Allow-Headers'))
            ->toContain('x-xsrf-token');
    });

    it('is answered for the CSRF cookie route', function (): void {
        // Sanctum's SPA flow fetches this before every write, so it preflights too.
        preflight('/sanctum/csrf-cookie', ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5174');
    });

    it('answers a route that needs authentication without authenticating it', function (): void {
        /*
         * A preflight carries no cookies by definition, so it must not be refused for being
         * unauthenticated — a 401 here would make every write from a browser fail.
         */
        preflight('/api/leads', ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])->assertNoContent();
    });

    it('does not grant a stranger access', function (): void {
        $response = preflight('/api/auth/login', ['HTTP_ORIGIN' => 'https://not-ours.example']);

        /*
         * The preflight is still answered — refusing to answer tells an attacker nothing useful
         * and breaks first-party clients when a configuration is wrong. What it does not do is
         * name the stranger as allowed, so the browser blocks the request that follows.
         */
        expect($response->headers->get('Access-Control-Allow-Origin'))
            ->not->toBe('https://not-ours.example');
    });
});
