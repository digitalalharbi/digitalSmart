<?php

declare(strict_types=1);

use App\Interface\Queue\ThrottleByProvider;
use Illuminate\Support\Facades\RateLimiter;

/** A queued job as far as the middleware can tell: it can be released, and it records that. */
final class ReleasableJobDouble
{
    public ?int $releasedFor = null;

    public function release(int $delay): void
    {
        $this->releasedFor = $delay;
    }
}

function releasable(): ReleasableJobDouble
{
    return new ReleasableJobDouble;
}

beforeEach(function (): void {
    RateLimiter::clear('provider-sync:x');
});

it('lets a provider through up to what it published, then releases rather than fails', function (): void {
    $middleware = new ThrottleByProvider('x');
    $ran = 0;
    $next = function () use (&$ran): void {
        $ran++;
    };

    $first = releasable();
    $middleware->handle($first, $next);

    $second = releasable();
    $middleware->handle($second, $next);

    // X publishes 250 a quarter-hour: one start a minute once halved and spread over a sync's calls.
    expect($ran)->toBe(1)
        ->and($first->releasedFor)->toBeNull()
        ->and($second->releasedFor)->toBeGreaterThan(0);
});

it('does not throttle a provider that publishes no limit', function (): void {
    $middleware = new ThrottleByProvider('meta');
    $ran = 0;

    foreach (range(1, 20) as $i) {
        $middleware->handle(releasable(), function () use (&$ran): void {
            $ran++;
        });
    }

    expect($ran)->toBe(20);
});

it('keeps one provider’s pace from slowing another', function (): void {
    $ran = ['x' => 0, 'hubspot' => 0];

    foreach (['x', 'x', 'x', 'hubspot', 'hubspot'] as $provider) {
        (new ThrottleByProvider($provider))->handle(releasable(), function () use (&$ran, $provider): void {
            $ran[$provider]++;
        });
    }

    expect($ran['x'])->toBe(1)->and($ran['hubspot'])->toBe(2);
    RateLimiter::clear('provider-sync:hubspot');
});
