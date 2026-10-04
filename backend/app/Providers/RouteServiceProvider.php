<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limiters.
 *
 * Defined centrally so every route can state its budget by name and the limits
 * are reviewable in one place.
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Default budget for authenticated API traffic.
        RateLimiter::for('api', fn (Request $request): Limit => $request->user() !== null
            ? Limit::perMinute(120)->by('user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute(30)->by('ip:'.(string) $request->ip()));

        // Monitors poll health frequently; keep it cheap but not unbounded.
        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('ip:'.(string) $request->ip()));

        // Credential endpoints are brute-force targets (used from Phase 1).
        // Two limits apply at once: per IP and per account.
        RateLimiter::for('auth', /** @return list<Limit> */ fn (Request $request): array => [
            Limit::perMinute(5)->by('ip:'.(string) $request->ip()),
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
        ]);
    }
}
