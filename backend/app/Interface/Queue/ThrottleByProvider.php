<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Domain\Integration\Provider;
use App\Domain\Integration\Sync\ProviderThrottle;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeps a provider's syncs inside what the provider has published about itself.
 *
 * A job over the limit is **released**, not failed: nothing is wrong with it, the provider just has to be given
 * room, and a failure would spend one of the job's tries on the scheduler's own enthusiasm. The delay is the time
 * until the window frees a slot plus a little jitter, so a hundred released jobs do not all come back in the same
 * second and release together again.
 */
final class ThrottleByProvider
{
    public function __construct(private readonly string $provider) {}

    public function handle(object $job, Closure $next): void
    {
        $limit = Provider::tryFrom($this->provider)?->rateLimit();
        $perMinute = ProviderThrottle::startsPerMinute($limit);

        if ($perMinute === null) {
            $next($job);

            return;
        }

        $key = 'provider-sync:'.$this->provider;

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            /** @phpstan-ignore-next-line the queued job exposes release() through InteractsWithQueue */
            $job->release(RateLimiter::availableIn($key) + random_int(1, 15));

            return;
        }

        RateLimiter::hit($key, 60);

        $next($job);
    }
}
