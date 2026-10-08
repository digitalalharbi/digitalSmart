<?php

declare(strict_types=1);

use App\Application\Identity\Exception\AuthenticationFailed;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Exception\LastOwnerProtected;
use App\Domain\Shared\Exception\DomainException;
use App\Interface\Console\SyncAccessCatalogue;
use App\Interface\Console\SyncAdvertisingData;
use App\Interface\Http\Middleware\AssignRequestId;
use App\Interface\Http\Middleware\AuthenticateSessionForSpa;
use App\Interface\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    /*
     * Registered explicitly rather than through `withRouting(channels: ...)`, so the authorisation endpoint
     * carries middleware. The default registration has none, which means an unauthenticated subscription is
     * answered rather than refused — and the channel callback never runs, because there is no actor to pass it.
     *
     * `web` for the session cookie this SPA authenticates with, and `auth` so a request with no session is a 401
     * from the framework rather than a channel rule quietly returning false.
     */
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['web', 'auth']],
    )
    ->withCommands([
        SyncAccessCatalogue::class,
        SyncAdvertisingData::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA authentication: Sanctum's stateful guard on /api.
        $middleware->statefulApi();

        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
            'throttle:api',
        ]);

        /*
         * A session is only as current as the password it was opened with.
         *
         * `AuthenticateSession` stores the password hash in the session and compares it on every request, so a
         * session opened with a password that has since been changed fails its next request instead of carrying
         * on. Without it, `logoutOtherDevices()` rehashes and nothing happens — the other sessions keep working,
         * and a password change made *because* somebody else knows the old one would achieve nothing at all.
         *
         * Appended to the stateful API group, after Sanctum has resolved the session. Token clients have no
         * session and the middleware passes them through untouched.
         */
        $middleware->api(append: [
            AuthenticateSessionForSpa::class,
        ]);

        /*
         * Where a guest is sent, as a path rather than a route name.
         *
         * This backend is API-only and has no `login` route, which is what the framework's default points at —
         * so an unauthenticated request that did **not** ask for JSON produced a 500 ("Route [login] not
         * defined") instead of a 401. Found by an end-to-end test fetching a file download without an
         * `Accept: application/json` header, which is exactly how a browser asks for one.
         *
         * The path is the SPA's own sign-in screen. For anything under `api/*` the exception renderer below still
         * answers 401 as JSON; this only decides what a non-JSON caller is told, and "go and sign in" is the
         * honest answer rather than a server error.
         */
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never leak credentials or tokens into logs or flashed input.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'access_token',
            'refresh_token',
            'client_secret',
        ]);

        // API-only: always answer with JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);

        $exceptions->render(static function (AuthenticationException $exception, Request $request): ?Response {
            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNAUTHORIZED);
        });

        // Authenticated but not permitted.
        $exceptions->render(static fn (AuthorizationDenied $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'required_permission' => $exception->permission,
        ], Response::HTTP_FORBIDDEN));

        /*
         * Missing, or outside the caller's workspace — the same 404 either way.
         * Confirming that a record exists elsewhere would itself leak across a
         * tenant boundary.
         */
        $exceptions->render(static fn (ResourceNotFound $exception): Response => response()->json([
            'message' => $exception->getMessage(),
        ], Response::HTTP_NOT_FOUND));

        // A broken business rule is a 422 with a stable machine-readable code.
        $exceptions->render(static fn (DomainException $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));

        /*
         * A provider we cannot reach is not a server error and not the user's
         * mistake: it is a documented external dependency. 503 with the exact
         * missing credential, so the response is actionable rather than just
         * red.
         */
        $exceptions->render(static fn (ProviderNotConfigured $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'code' => 'provider_not_configured',
            'provider' => $exception->provider->value,
            'missing_credentials' => $exception->missingKeys,
            'blocked_external' => true,
        ], Response::HTTP_SERVICE_UNAVAILABLE));

        // The capability does not exist to be used — no credential would help.
        $exceptions->render(static fn (ProviderUnavailable $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'code' => 'provider_unavailable',
            'provider' => $exception->provider->value,
            'blocked_external' => true,
        ], Response::HTTP_NOT_IMPLEMENTED));

        // A normalised provider failure, with whatever retry guidance we have.
        $exceptions->render(static fn (ProviderRequestFailed $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'code' => 'provider_request_failed',
            'provider' => $exception->provider->value,
            'retryable' => $exception->isRetryable(),
            'retry_after_seconds' => $exception->retryAfterSeconds,
        ], Response::HTTP_BAD_GATEWAY));

        // Protecting the last owner is a business rule, not a permission failure.
        $exceptions->render(static fn (LastOwnerProtected $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'code' => 'last_owner_protected',
        ], Response::HTTP_UNPROCESSABLE_ENTITY));

        // Rejected credentials read as a validation failure on the login form.
        $exceptions->render(static fn (AuthenticationFailed $exception): Response => response()->json([
            'message' => $exception->getMessage(),
            'errors' => ['email' => [$exception->getMessage()]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    })->create();
