<?php

declare(strict_types=1);

use App\Domain\Tracking\Cost\CostMetrics;
use App\Domain\Tracking\Cost\LinkSpend;

it('divides spend by a count and rounds to the minor unit', function (): void {
    $costs = CostMetrics::compute(LinkSpend::direct(10_000, 'SAR', 'campaign', 14), ['cpc' => 3, 'cpl' => 4, 'cac' => 1]);

    expect($costs['cpc'])->toBe(['minor' => 3333, 'currency' => 'SAR', 'reason' => null])
        ->and($costs['cpl']['minor'])->toBe(2500)
        ->and($costs['cac']['minor'])->toBe(10_000);
});

it('gives no cost where spend is unavailable, and says why instead of showing zero', function (): void {
    $costs = CostMetrics::compute(LinkSpend::unavailable('Shared with another link.', 'campaign'), ['cpc' => 10, 'cpl' => 2]);

    foreach ($costs as $metric) {
        expect($metric['minor'])->toBeNull()->and($metric['reason'])->toBe('Shared with another link.');
    }
});

it('does not divide by nothing and does not treat an unreported count as zero', function (): void {
    $costs = CostMetrics::compute(LinkSpend::direct(5_000, 'SAR', 'ad', 3), ['cpc' => 0, 'cpl' => null]);

    expect($costs['cpc']['minor'])->toBeNull()->and($costs['cpc']['reason'])->toContain('No tracked clicks yet')
        ->and($costs['cpl']['minor'])->toBeNull()->and($costs['cpl']['reason'])->toContain('not reported')
        ->and($costs['cac']['reason'])->toContain('not reported');
});

it('keeps spend of zero distinct from no spend: a zero spend still yields a zero cost per unit', function (): void {
    $costs = CostMetrics::compute(LinkSpend::direct(0, 'SAR', 'ad', 3), ['cpl' => 5]);

    expect($costs['cpl']['minor'])->toBe(0);
});

it('computes return on spend only for one currency, a real spend and a stated revenue', function (): void {
    $spend = LinkSpend::direct(2_000, 'SAR', 'campaign', 7);

    expect(CostMetrics::roas($spend, 5_000, 'SAR'))->toBe(['value' => 2.5, 'reason' => null])
        ->and(CostMetrics::roas($spend, 5_000, 'AED')['value'])->toBeNull()
        ->and(CostMetrics::roas($spend, null, null)['reason'])->toContain('No revenue')
        ->and(CostMetrics::roas(LinkSpend::direct(0, 'SAR', 'ad', 1), 100, 'SAR')['reason'])->toContain('zero')
        ->and(CostMetrics::roas(LinkSpend::unavailable('nope'), 100, 'SAR')['reason'])->toBe('nope');
});
