<?php

declare(strict_types=1);

use App\Domain\Analytics\CreativeAssessor;
use App\Domain\Analytics\CreativeFigures;
use App\Domain\Analytics\CreativeVerdict;

/*
|--------------------------------------------------------------------------
| Calling a creative by its own figures
|--------------------------------------------------------------------------
|
| Plain rules with floors. What is asserted is mostly refusals: no verdict below the impression floor, no fatigue from one
| figure, no top or weak without a cohort, no percentage from a zero.
|
*/

function creativeFigures(
    float $impressions = 10_000,
    float $spend = 100,
    ?float $ctr = 2.0,
    ?float $frequency = null,
    ?float $cost = null,
    ?float $results = 5,
    ?float $conversionRate = null,
): CreativeFigures {
    return new CreativeFigures($impressions, $spend, $ctr, $frequency, $cost, $results, $conversionRate);
}

/**
 * @param  array<string, CreativeFigures>  $current
 * @param  array<string, CreativeFigures>  $previous
 */
function creativeVerdict(array $current, array $previous = [], string $key = 'a'): ?CreativeVerdict
{
    return (new CreativeAssessor)->assess($current, $previous)[$key]->verdict;
}

it('gives no verdict to a creative seen too little, whatever its figures', function (): void {
    $assessments = (new CreativeAssessor)->assess(
        ['a' => creativeFigures(impressions: CreativeAssessor::MIN_IMPRESSIONS - 1, ctr: 9.0)],
        ['a' => creativeFigures(ctr: 1.0, cost: 5)],
    );

    expect($assessments['a']->verdict)->toBeNull()->and($assessments['a']->evidence)->toBe([]);
});

it('calls a creative fatiguing only when its click-through fell and something else got worse', function (): void {
    $current = ['a' => creativeFigures(ctr: 1.4, frequency: 3.6)];
    $before = ['a' => creativeFigures(ctr: 2.0, frequency: 3.0)];

    expect(creativeVerdict($current, $before))->toBe(CreativeVerdict::Fatiguing);

    $evidence = (new CreativeAssessor)->assess($current, $before)['a']->evidence;

    expect(array_column($evidence, 'signal'))->toContain('ctr_change')->toContain('frequency_change');
});

it('corroborates with cost per result and with a falling conversion rate as well', function (): void {
    expect(creativeVerdict(['a' => creativeFigures(ctr: 1.4, cost: 130)], ['a' => creativeFigures(ctr: 2.0, cost: 100)]))->toBe(CreativeVerdict::Fatiguing)
        ->and(creativeVerdict(['a' => creativeFigures(ctr: 1.4, conversionRate: 2.0)], ['a' => creativeFigures(ctr: 2.0, conversionRate: 3.0)]))->toBe(CreativeVerdict::Fatiguing);
});

it('does not call fatigue on one figure: a fall nothing corroborates is named and left unclassified', function (): void {
    $assessment = (new CreativeAssessor)->assess(['a' => creativeFigures(ctr: 1.4)], ['a' => creativeFigures(ctr: 2.0)])['a'];

    expect($assessment->verdict)->toBeNull()
        ->and(array_column($assessment->evidence, 'signal'))->toBe(['ctr_change']);
});

it('does not call fatigue when the other figures held', function (): void {
    expect(creativeVerdict(['a' => creativeFigures(ctr: 1.4, frequency: 3.0, cost: 100)], ['a' => creativeFigures(ctr: 2.0, frequency: 3.0, cost: 100)]))->toBeNull();
});

it('needs the earlier period to clear the floor too, or there is no comparison', function (): void {
    $current = ['a' => creativeFigures(ctr: 1.0, frequency: 5.0)];
    $before = ['a' => creativeFigures(impressions: 500, ctr: 2.0, frequency: 2.0)];

    expect((new CreativeAssessor)->assess($current, $before)['a']->evidence)->toBe([])
        ->and(creativeVerdict($current, $before))->not->toBe(CreativeVerdict::Fatiguing);
});

it('calls a creative rising on a quarter more click-through, unless a result got dearer', function (): void {
    expect(creativeVerdict(['a' => creativeFigures(ctr: 2.6)], ['a' => creativeFigures(ctr: 2.0)]))->toBe(CreativeVerdict::Rising)
        ->and(creativeVerdict(['a' => creativeFigures(ctr: 2.6, cost: 140)], ['a' => creativeFigures(ctr: 2.0, cost: 100)]))->not->toBe(CreativeVerdict::Rising);
});

it('has no change from a base of zero or of nothing', function (): void {
    $assessment = (new CreativeAssessor)->assess(['a' => creativeFigures(ctr: 2.0)], ['a' => creativeFigures(ctr: 0.0)])['a'];

    expect($assessment->evidence)->toBe([]);
});

it('ranks no top and no weak in a cohort too small to select from', function (): void {
    $current = [
        'a' => creativeFigures(results: 50, ctr: 5.0),
        'b' => creativeFigures(results: 0, spend: 500, ctr: 0.1),
        'c' => creativeFigures(results: 3),
    ];

    $verdicts = array_map(static fn ($a) => $a->verdict, (new CreativeAssessor)->assess($current, []));

    expect($verdicts)->toBe(['a' => CreativeVerdict::Stable, 'b' => CreativeVerdict::Stable, 'c' => CreativeVerdict::Stable]);
});

it('names the leaders of a real cohort top, and a creative that took its share and returned nothing weak', function (): void {
    $current = [
        'best' => creativeFigures(results: 40, ctr: 3.0, spend: 200),
        'good' => creativeFigures(results: 10, ctr: 2.5, spend: 150),
        'ok' => creativeFigures(results: 4, ctr: 2.0, spend: 60),
        'dud' => creativeFigures(results: 0, ctr: 1.9, spend: 400),
        'small' => creativeFigures(results: 0, ctr: 1.0, spend: 10),
        'dark' => creativeFigures(impressions: 20, results: 9, ctr: 9.0),
    ];

    $verdicts = array_map(static fn ($a) => $a->verdict, (new CreativeAssessor)->assess($current, []));

    expect($verdicts['best'])->toBe(CreativeVerdict::Top)
        ->and($verdicts['dud'])->toBe(CreativeVerdict::Weak)
        // Barely run, so it has not been given the chance to be weak.
        ->and($verdicts['small'])->toBe(CreativeVerdict::Stable)
        ->and($verdicts['dark'])->toBeNull();
});

it('judges top on click-through alone where nothing in the cohort has a result, and does not pretend otherwise', function (): void {
    $current = [
        'a' => creativeFigures(results: null, ctr: 4.0),
        'b' => creativeFigures(results: null, ctr: 2.0),
        'c' => creativeFigures(results: null, ctr: 1.5),
        'd' => creativeFigures(results: null, ctr: 1.0),
    ];

    $verdicts = array_map(static fn ($a) => $a->verdict, (new CreativeAssessor)->assess($current, []));

    expect($verdicts['a'])->toBe(CreativeVerdict::Top)
        // No result is not zero results: nothing here was measured to have returned nothing.
        ->and($verdicts['d'])->not->toBe(CreativeVerdict::Weak);
});
