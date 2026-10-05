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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
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
