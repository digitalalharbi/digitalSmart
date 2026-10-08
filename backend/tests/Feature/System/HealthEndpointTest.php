<?php

declare(strict_types=1);

use App\Application\Shared\Contract\ApplicationInfo;
use App\Application\Shared\Contract\HealthProbe;
use App\Application\Shared\DTO\ServiceHealth;
use App\Application\System\Query\GetSystemHealth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

it('reports healthy services against the real database and cache', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertOk()
        ->assertJsonPath('data.application', 'digitalSmart')
        ->assertJsonPath('data.environment', 'testing')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure([
            'data' => [
                'application',
                'version',
                'environment',
                'status',
                'services' => [['name', 'status', 'detail']],
            ],
        ]);

    $services = $response->json('data.services');
    /*
     * Four, and the fourth is its own line on purpose: an empty queue and an empty queue because the work was
     * abandoned are the same depth, and only one of them is fine.
     */
    expect(array_column($services, 'name'))->toBe(['database', 'cache', 'queue', 'failed_jobs']);
});

/** Build the real use case with a fixed probe result. */
function healthWith(ServiceHealth ...$results): GetSystemHealth
{
    $probes = array_map(
        static fn (ServiceHealth $result): HealthProbe => new class($result) implements HealthProbe
        {
            public function __construct(private ServiceHealth $result) {}

            public function probe(): ServiceHealth
            {
                return $this->result;
            }
        },
        $results,
    );

    return new GetSystemHealth(app(ApplicationInfo::class), $probes);
}

it('returns 503 when a dependency is down so monitors react', function (): void {
    $this->instance(GetSystemHealth::class, healthWith(
        ServiceHealth::down('database', 'connection refused'),
    ));

    $this->getJson('/api/health')
        ->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE)
        ->assertJsonPath('data.status', 'down')
        ->assertJsonPath('data.services.0.detail', 'connection refused');
});

it('still returns 200 when only a non-critical dependency is degraded', function (): void {
    $this->instance(GetSystemHealth::class, healthWith(
        ServiceHealth::ok('database', 'pgsql 16'),
        ServiceHealth::degraded('queue', 'no worker heartbeat'),
    ));

    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'degraded');
});

it('stamps a correlation id on every response', function (): void {
    $response = $this->getJson('/api/health');

    $requestId = $response->headers->get('X-Request-Id');
    expect($requestId)->toBeUuid();
});

it('echoes a caller-supplied correlation id when it is a valid uuid', function (): void {
    $given = '0193f0f4-2b7a-7cc0-9a1b-4a5f6d7e8c90';

    $this->withHeader('X-Request-Id', $given)
        ->getJson('/api/health')
        ->assertHeader('X-Request-Id', $given);
});

it('rejects a malicious correlation id instead of echoing it into logs', function (): void {
    $response = $this->withHeader('X-Request-Id', "not-a-uuid\ninjected log line")
        ->getJson('/api/health');

    expect($response->headers->get('X-Request-Id'))->not->toBe("not-a-uuid\ninjected log line")
        ->and($response->headers->get('X-Request-Id'))->toBeUuid();
});

it('applies hardening headers and removes stack disclosure', function (): void {
    $response = $this->getJson('/api/health');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('X-Powered-By'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('answers unknown api routes with json, never html', function (): void {
    $response = $this->getJson('/api/this-route-does-not-exist');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});

it('exposes the framework health probe for load balancers', function (): void {
    $this->get('/up')->assertOk();
});

it('registers no web routes: this is an api-only service', function (): void {
    // Only framework/tooling routes may use the web group (session auth for the
    // CSRF cookie, broadcasting auth, Horizon and the OpenAPI viewer).
    $webRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('web', $route->gatherMiddleware(), true))
        ->reject(fn ($route): bool => str_starts_with((string) $route->uri(), 'sanctum/'))
        ->reject(fn ($route): bool => str_starts_with((string) $route->uri(), 'horizon'))
        ->reject(fn ($route): bool => str_starts_with((string) $route->uri(), 'broadcasting/'))
        ->reject(fn ($route): bool => str_starts_with((string) $route->uri(), 'docs/'))
        ->reject(fn ($route): bool => str_starts_with((string) $route->uri(), '_scramble/'))
        ->map(fn ($route): string => $route->uri());

    expect($webRoutes->all())->toBe([]);
});
