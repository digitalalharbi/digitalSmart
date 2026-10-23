<?php

declare(strict_types=1);

use App\Domain\Leads\Automation\LeadSla;
use App\Domain\Leads\Automation\SlaState;

function slaAt(string $received, ?string $attempt, ?string $contact, int $minutes, string $now): LeadSla
{
    return LeadSla::judge(
        new DateTimeImmutable($received),
        $attempt === null ? null : new DateTimeImmutable($attempt),
        $contact === null ? null : new DateTimeImmutable($contact),
        $minutes,
        new DateTimeImmutable($now),
    );
}

it('is within the response time until three quarters have passed, then approaching, then overdue', function (): void {
    $r = '2026-10-23T10:00:00+00:00';

    expect(slaAt($r, null, null, 40, '2026-10-23T10:29:59+00:00')->state)->toBe(SlaState::Within)
        ->and(slaAt($r, null, null, 40, '2026-10-23T10:30:00+00:00')->state)->toBe(SlaState::Approaching)
        ->and(slaAt($r, null, null, 40, '2026-10-23T10:39:59+00:00')->state)->toBe(SlaState::Approaching)
        ->and(slaAt($r, null, null, 40, '2026-10-23T10:40:00+00:00')->state)->toBe(SlaState::Overdue);
});

it('keeps an attempt apart from a contact and judges the promise on the attempt', function (): void {
    $r = '2026-10-23T10:00:00+00:00';
    $attempted = slaAt($r, '2026-10-23T10:20:00+00:00', null, 30, '2026-10-23T12:00:00+00:00');
    $late = slaAt($r, '2026-10-23T10:45:00+00:00', null, 30, '2026-10-23T12:00:00+00:00');
    $contacted = slaAt($r, '2026-10-23T10:10:00+00:00', '2026-10-23T10:50:00+00:00', 30, '2026-10-23T12:00:00+00:00');

    expect($attempted->state)->toBe(SlaState::Attempted)->and($attempted->met)->toBeTrue()->and($attempted->secondsToAttempt)->toBe(1200)
        ->and($late->state)->toBe(SlaState::Attempted)->and($late->met)->toBeFalse()
        ->and($contacted->state)->toBe(SlaState::Contacted);
});

it('reports a lead nobody has touched as not yet met, never as met', function (): void {
    $within = slaAt('2026-10-23T10:00:00+00:00', null, null, 30, '2026-10-23T10:05:00+00:00');
    $overdue = slaAt('2026-10-23T10:00:00+00:00', null, null, 30, '2026-10-23T11:00:00+00:00');

    expect($within->met)->toBeNull()->and($overdue->met)->toBeFalse();
});

it('counts elapsed minutes across midnight and a time-zone offset without drifting', function (): void {
    /* Arrived at 23:50 Riyadh (UTC+3) = 20:50 UTC; ten minutes later is past midnight there and not in UTC. */
    $sla = slaAt('2026-10-23T23:50:00+03:00', null, null, 20, '2026-10-24T00:11:00+03:00');

    expect($sla->state)->toBe(SlaState::Overdue)
        ->and($sla->dueAt->setTimezone(new DateTimeZone('Asia/Riyadh'))->format('Y-m-d H:i'))->toBe('2026-10-24 00:10');
});
