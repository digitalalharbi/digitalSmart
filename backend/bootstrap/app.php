<?php

declare(strict_types=1);

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
    })->create();
