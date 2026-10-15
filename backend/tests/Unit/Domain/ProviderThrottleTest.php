<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderRateLimit;
use App\Domain\Integration\Sync\ProviderThrottle;

it('does not invent a ceiling for a provider that publishes none', function (): void {
    expect(ProviderThrottle::startsPerMinute(ProviderRateLimit::undocumented()))->toBeNull()
        ->and(ProviderThrottle::startsPerMinute(null))->toBeNull()
        ->and(ProviderThrottle::startsPerMinute(Provider::Meta->rateLimit()))->toBeNull();
});

it('starts at most half the published rate, spread over the calls a sync costs', function (): void {
    // 250 per 900 s = 16.67 a minute; half is 8.3; six calls a sync is 1.38 syncs, floored.
    expect(ProviderThrottle::startsPerMinute(Provider::X->rateLimit()))->toBe(1)
        // 190 per 10 s = 1,140 a minute, but 625,000 a day = 434 a minute is the tighter figure.
        ->and(ProviderThrottle::startsPerMinute(Provider::HubSpot->rateLimit()))->toBe(36);
});

it('never drops below one, so a tightly limited provider is still synced', function (): void {
    expect(ProviderThrottle::startsPerMinute(new ProviderRateLimit(requests: 1, windowSeconds: 3600)))->toBe(1);
});

it('honours the tighter of the window and the day', function (): void {
    $limit = new ProviderRateLimit(requests: 6000, windowSeconds: 60, dailyRequests: 14_400);

    // 6,000 a minute against 10 a minute: the day binds.
    expect(ProviderThrottle::startsPerMinute($limit, 1))->toBe(5);
});

it('leaves a LinkedIn-style volume limit alone, since it is not a request count', function (): void {
    expect(ProviderThrottle::startsPerMinute(Provider::LinkedIn->rateLimit()))->toBeNull();
});
