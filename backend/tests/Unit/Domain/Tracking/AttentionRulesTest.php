<?php

declare(strict_types=1);

use App\Domain\Tracking\Operations\AttentionRules;

/**
 * @param  array<string, mixed>  $over
 * @return array{id: string, name: string, clicks: int, landing_views: ?int, leads: int, qualified: int, appointments: int, sales: int, spend_minor: ?int, spend_basis: string, shared_spend_minor: ?int, cpl_minor: ?int, landing_reported: bool}
 */
function opsLink(array $over = []): array
{
    /** @var array{id: string, name: string, clicks: int, landing_views: ?int, leads: int, qualified: int, appointments: int, sales: int, spend_minor: ?int, spend_basis: string, shared_spend_minor: ?int, cpl_minor: ?int, landing_reported: bool} $link */
    $link = [
        'id' => 'l1', 'name' => 'Link', 'clicks' => 100, 'landing_views' => 90, 'leads' => 20, 'qualified' => 10, 'appointments' => 6,
        'sales' => 3, 'spend_minor' => 10_000, 'spend_basis' => 'DIRECT', 'shared_spend_minor' => null, 'cpl_minor' => 500,
        'landing_reported' => true, ...$over,
    ];

    return $link;
}

const OPS_TEAM = ['sla_minutes' => 30, 'leads' => 20, 'attempted_within_sla' => 18, 'overdue' => 0, 'median_attempt_seconds' => 600];

/**
 * @param  list<array<string, mixed>>  $items
 * @return list<mixed>
 */
function opsCodes(array $items): array
{
    return array_column($items, 'code');
}

it('says nothing about a healthy link', function (): void {
    expect(AttentionRules::evaluate([opsLink()], OPS_TEAM))->toBe([]);
});

it('flags spend with no leads only once there are enough clicks to expect some', function (): void {
    expect(opsCodes(AttentionRules::evaluate([opsLink(['leads' => 0, 'qualified' => 0, 'appointments' => 0, 'sales' => 0])], OPS_TEAM)))->toContain('spend_no_leads')
        ->and(opsCodes(AttentionRules::evaluate([opsLink(['leads' => 0, 'qualified' => 0, 'appointments' => 0, 'sales' => 0, 'clicks' => 12])], OPS_TEAM)))->not->toContain('spend_no_leads')
        ->and(opsCodes(AttentionRules::evaluate([opsLink(['leads' => 0, 'qualified' => 0, 'appointments' => 0, 'sales' => 0, 'spend_basis' => 'UNAVAILABLE', 'spend_minor' => null])], OPS_TEAM)))->not->toContain('spend_no_leads');
});

it('does not read a rate off a handful of leads', function (): void {
    $few = opsLink(['leads' => 4, 'qualified' => 0, 'appointments' => 0, 'sales' => 0, 'cpl_minor' => 2500]);
    $enough = opsLink(['leads' => 12, 'qualified' => 1]);

    expect(opsCodes(AttentionRules::evaluate([$few], OPS_TEAM)))->not->toContain('low_qualification')
        ->and(opsCodes(AttentionRules::evaluate([$enough], OPS_TEAM)))->toContain('low_qualification');
});

it('reads a step rate only over enough of the step before it', function (): void {
    expect(opsCodes(AttentionRules::evaluate([opsLink(['qualified' => 8, 'appointments' => 1])], OPS_TEAM)))->toContain('qualified_few_appointments')
        ->and(opsCodes(AttentionRules::evaluate([opsLink(['qualified' => 3, 'appointments' => 0])], OPS_TEAM)))->not->toContain('qualified_few_appointments')
        ->and(opsCodes(AttentionRules::evaluate([opsLink(['appointments' => 8, 'sales' => 1])], OPS_TEAM)))->toContain('appointments_few_sales');
});

it('compares cost per lead only across enough links, against their median', function (): void {
    $cheap = static fn (string $id): array => opsLink(['id' => $id, 'name' => $id, 'cpl_minor' => 400]);
    $dear = opsLink(['id' => 'dear', 'name' => 'dear', 'cpl_minor' => 900]);

    expect(opsCodes(AttentionRules::evaluate([$cheap('a'), $dear], OPS_TEAM)))->not->toContain('high_cpl')
        ->and(opsCodes(AttentionRules::evaluate([$cheap('a'), $cheap('b'), $dear], OPS_TEAM)))->toContain('high_cpl');
});

it('reports campaign spend that cannot be tied to a link, and a destination page that is not reporting', function (): void {
    $shared = opsLink(['spend_basis' => 'UNAVAILABLE', 'spend_minor' => null, 'shared_spend_minor' => 50_000]);
    $silent = opsLink(['landing_reported' => false, 'landing_views' => null]);

    expect(opsCodes(AttentionRules::evaluate([$shared], OPS_TEAM)))->toContain('spend_not_attributable')
        ->and(opsCodes(AttentionRules::evaluate([$silent], OPS_TEAM)))->toContain('tracking_coverage');
});

it('flags slow follow-up only when a response time was promised and enough leads came', function (): void {
    $slow = [...OPS_TEAM, 'attempted_within_sla' => 4, 'overdue' => 9];

    expect(opsCodes(AttentionRules::evaluate([opsLink()], $slow)))->toContain('slow_follow_up')
        ->and(opsCodes(AttentionRules::evaluate([opsLink()], [...$slow, 'sla_minutes' => null])))->not->toContain('slow_follow_up')
        ->and(opsCodes(AttentionRules::evaluate([opsLink()], [...$slow, 'leads' => 3])))->not->toContain('slow_follow_up');
});
