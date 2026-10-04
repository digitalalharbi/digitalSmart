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
            ? Limit::perMinute($this->limit('api_user'))->by('user:'.$request->user()->getAuthIdentifier())
            : Limit::perMinute($this->limit('api_guest'))->by('ip:'.(string) $request->ip()));

        // Monitors poll health frequently; keep it cheap but not unbounded.
        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute($this->limit('health'))
            ->by('ip:'.(string) $request->ip()));

        /*
         * Credential endpoints are brute-force targets. Two limits apply at
         * once: per IP and per e-mail. The per-account limit is the one that
         * protects a specific user, and it stays in force even where the
         * per-IP budget is raised for a shared address.
         */
        RateLimiter::for('auth', /** @return list<Limit> */ fn (Request $request): array => [
            Limit::perMinute($this->limit('auth'))->by('ip:'.(string) $request->ip()),
            Limit::perMinute($this->limit('auth'))->by('email:'.strtolower((string) $request->input('email'))),
        ]);
    }

    private function limit(string $name): int
    {
        $value = config('digitalsmart.rate_limits.'.$name);

        return is_int($value) && $value > 0 ? $value : 5;
    }
}
