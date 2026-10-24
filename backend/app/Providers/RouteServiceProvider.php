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
        /*
         * Store webhooks. Public, so it needs a ceiling; generous, because a busy shop on a sale day
         * genuinely delivers hundreds of events a minute and throttling a real client's orders would
         * be worse than the abuse it prevents.
         *
         * Keyed by merchant rather than by IP: providers deliver from shared infrastructure, so an IP
         * key would let one noisy shop throttle every other shop on the same platform. A 429 is the
         * right answer here — the provider redelivers, and nothing is lost.
         */
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute($this->limit('webhooks'))
            ->by('merchant:'.(string) $request->input('merchant', $request->ip())));

        /*
         * Shared report links, keyed by address.
         *
         * Tighter than the authenticated API's bucket, which is keyed per user — this endpoint has no user, and a
         * bare token in a URL is the one thing in this product worth brute forcing. A legitimate recipient opens a
         * link a handful of times; anybody needing more than that per minute is not reading a report.
         */
        RateLimiter::for('shared-reports', fn (Request $request): Limit => Limit::perMinute(
            $this->limit('shared_reports'),
        )->by('ip:'.(string) $request->ip()));

        RateLimiter::for('access-requests', fn (Request $request): Limit => Limit::perMinute(
            $this->limit('access_requests'),
        )->by('ip:'.(string) $request->ip()));

        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(
            $this->limit('invitations'),
        )->by('ip:'.(string) $request->ip()));

        /* Followed links and page beacons: public, so keyed by address and generous enough for a shared office network. */
        RateLimiter::for('tracking', fn (Request $request): Limit => Limit::perMinute(
            $this->limit('tracking'),
        )->by('ip:'.(string) $request->ip()));

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
