<?php

declare(strict_types=1);

namespace App\Domain\Integration\Sync;

use App\Domain\Integration\ProviderRateLimit;

/**
 * How many account syncs a provider can be asked to *start* per minute, from what it has published.
 *
 * Only from what it has published: an undocumented limit yields `null`, meaning "do not self-throttle, back
 * off on the provider's own 429" — the same rule `ProviderRateLimit::undocumented()` states. Inventing a
 * ceiling would make syncs slow for a reason nobody can find.
 *
 * Half the published rate, divided by the calls one sync is assumed to spend. Half because the published number
 * is a ceiling, the deployment shares it with everything else it does (a report, a discovery, a manual read),
 * and a scheduler that runs at the ceiling gets throttled by its own retries. Never below one: a provider with
 * a tiny published limit still has to be synced, slowly.
 */
final class ProviderThrottle
{
    /** What one account sync is assumed to cost: structure, then campaigns, ad groups, ads and the insight reads. */
    public const int CALLS_PER_SYNC = 6;

    public static function startsPerMinute(?ProviderRateLimit $limit, int $callsPerSync = self::CALLS_PER_SYNC): ?int
    {
        if ($limit === null) {
            return null;
        }

        $perMinute = [];

        if ($limit->requests !== null && $limit->windowSeconds !== null && $limit->windowSeconds > 0) {
            $perMinute[] = $limit->requests * 60 / $limit->windowSeconds;
        }

        if ($limit->dailyRequests !== null) {
            $perMinute[] = $limit->dailyRequests / (24 * 60);
        }

        if ($perMinute === []) {
            return null;
        }

        return max(1, (int) floor(min($perMinute) * 0.5 / max(1, $callsPerSync)));
    }
}
