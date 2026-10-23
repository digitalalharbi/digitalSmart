<?php

declare(strict_types=1);

use App\Domain\Tracking\Exception\InvalidFunnel;
use App\Domain\Tracking\Funnel\FunnelCalculator;
use App\Domain\Tracking\Funnel\FunnelCohort;
use App\Domain\Tracking\Funnel\FunnelDefinition;

/**
 * @param  list<string>  $sources
 * @return list<App\Domain\Tracking\Funnel\FunnelStage>
 */
function stagesOf(array $sources): array
{
    return FunnelDefinition::stagesFrom(array_values(array_map(static fn (string $s): array => ['source' => $s], $sources)));
}

/**
 * @param  list<array{started: int, landing: ?int, form_start: ?int, submit: ?int, lead: ?string}>  $sessions
 * @param  array<string, array{received: int, contacted: ?int, qualified: ?int, appointment: ?int, opportunity: ?int, won: ?int}>  $leads
 */
function cohortOf(array $sessions, array $leads = [], ?int $submissions = null): FunnelCohort
{
    return new FunnelCohort($sessions, $submissions ?? count(array_filter($sessions, static fn (array $s): bool => $s['submit'] !== null)), $leads);
}

/** @return array{started: int, landing: ?int, form_start: ?int, submit: ?int, lead: ?string} */
function visitOf(int $t, ?int $landing = null, ?int $start = null, ?int $submit = null, ?string $lead = null): array
{
    return ['started' => $t, 'landing' => $landing, 'form_start' => $start, 'submit' => $submit, 'lead' => $lead];
}

it('refuses a funnel that is out of order, repeated, empty or uses a source it does not record', function (array $raw): void {
    /** @var list<array<string, mixed>> $raw */
    expect(fn () => FunnelDefinition::stagesFrom($raw))->toThrow(InvalidFunnel::class);
})->with([
    'single' => [[['source' => 'link_click']]],
    'out of order' => [[['source' => 'lead_created'], ['source' => 'link_click']]],
    'repeated' => [[['source' => 'link_click'], ['source' => 'link_click']]],
    'unknown' => [[['source' => 'link_click'], ['source' => 'page_scroll']]],
]);

it('divides every stage by the one before it and the whole by the first', function (): void {
    $leads = ['a' => ['received' => 130, 'contacted' => 200, 'qualified' => null, 'appointment' => null, 'opportunity' => null, 'won' => null]];
    $result = FunnelCalculator::compute(
        stagesOf(['link_click', 'landing_view', 'form_submit', 'lead_created', 'lead_contacted']),
        cohortOf([visitOf(0, 10, 20, 100, 'a'), visitOf(0, 12), visitOf(0, 15), visitOf(0)], $leads),
    );

    [$click, $landing, $submit, $lead, $contacted] = $result['stages'];

    expect($click['count'])->toBe(4)
        ->and($landing['count'])->toBe(3)->and($landing['conversion_from_previous'])->toBe(0.75)->and($landing['drop_off'])->toBe(1)
        ->and($submit['count'])->toBe(1)->and($submit['conversion_from_previous'])->toBe(0.3333)
        ->and($lead['count'])->toBe(1)->and($lead['conversion_from_previous'])->toBe(1.0)
        ->and($contacted['count'])->toBe(1)
        ->and($contacted['median_seconds_from_previous'])->toBe(70)
        ->and($result['overall_conversion'])->toBe(0.25);
});

it('reports a step the page never reported as unknown, not as zero, and gives no rate for it', function (): void {
    $result = FunnelCalculator::compute(stagesOf(['link_click', 'landing_view', 'form_submit']), cohortOf([visitOf(0), visitOf(0)]));

    expect($result['stages'][1]['count'])->toBeNull()
        ->and($result['stages'][1]['state'])->toBe('partial')
        ->and($result['stages'][1]['reason'])->not->toBeEmpty()
        ->and($result['stages'][1]['conversion_from_previous'])->toBeNull()
        ->and($result['stages'][2]['conversion_from_previous'])->toBeNull();
});

it('does not turn a later stage that exceeds an earlier one into a rate above one hundred per cent', function (): void {
    $result = FunnelCalculator::compute(
        stagesOf(['link_click', 'form_start', 'form_submit']),
        cohortOf([visitOf(0, null, 5, 9), visitOf(0, null, null, 9)]),
    );

    expect($result['stages'][1]['count'])->toBe(1)
        ->and($result['stages'][2]['count'])->toBe(2)
        ->and($result['stages'][2]['conversion_from_previous'])->toBeNull()
        ->and($result['stages'][2]['conversion_reason'])->toContain('not being recorded');
});

it('says nothing about a rate when nobody reached the first step', function (): void {
    $result = FunnelCalculator::compute(stagesOf(['link_click', 'lead_created']), cohortOf([]));

    expect($result['stages'][0]['count'])->toBe(0)->and($result['overall_conversion'])->toBeNull()->and($result['overall_reason'])->not->toBeNull();
});

it('never produces a negative wait for a lead that was known before the visit', function (): void {
    $leads = ['old' => ['received' => 10, 'contacted' => 20, 'qualified' => null, 'appointment' => null, 'opportunity' => null, 'won' => null]];
    $result = FunnelCalculator::compute(stagesOf(['form_submit', 'lead_contacted']), cohortOf([visitOf(0, null, null, 500, 'old')], $leads));

    expect($result['stages'][1]['median_seconds_from_previous'])->toBeNull();
});
