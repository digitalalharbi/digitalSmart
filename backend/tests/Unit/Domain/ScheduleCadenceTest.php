<?php

declare(strict_types=1);

use App\Domain\Export\ScheduleCadence;

it('runs a daily schedule later today when its hour has not passed, tomorrow otherwise', function (): void {
    $from = new DateTimeImmutable('2026-10-15 05:30:00');

    expect(ScheduleCadence::Daily->next($from, 6, null, null)->format('Y-m-d H:i'))->toBe('2026-10-15 06:00')
        ->and(ScheduleCadence::Daily->next($from, 5, null, null)->format('Y-m-d H:i'))->toBe('2026-10-16 05:00');
});

it('never returns the instant it was asked from', function (): void {
    $from = new DateTimeImmutable('2026-10-15 06:00:00');

    expect(ScheduleCadence::Daily->next($from, 6, null, null))->toBeGreaterThan($from);
});

it('finds the next requested weekday, a week on if today already passed', function (): void {
    // 2026-10-15 is a Thursday (w=4).
    $thursdayAfterHour = new DateTimeImmutable('2026-10-15 09:00:00');

    expect(ScheduleCadence::Weekly->next($thursdayAfterHour, 8, 1, null)->format('Y-m-d l'))->toBe('2026-10-19 Monday')
        ->and(ScheduleCadence::Weekly->next($thursdayAfterHour, 8, 4, null)->format('Y-m-d'))->toBe('2026-10-22')
        ->and(ScheduleCadence::Weekly->next($thursdayAfterHour, 10, 4, null)->format('Y-m-d H'))->toBe('2026-10-15 10');
});

it('caps the day of the month so February is never skipped', function (): void {
    $from = new DateTimeImmutable('2026-01-30 12:00:00');

    expect(ScheduleCadence::Monthly->next($from, 6, null, 31)->format('Y-m-d'))->toBe('2026-02-28')
        ->and(ScheduleCadence::Monthly->next(new DateTimeImmutable('2026-10-01 01:00:00'), 6, null, 1)->format('Y-m-d'))->toBe('2026-10-01')
        ->and(ScheduleCadence::Monthly->next(new DateTimeImmutable('2026-10-01 07:00:00'), 6, null, 1)->format('Y-m-d'))->toBe('2026-11-01');
});

it('rolls a December monthly run into January of the next year', function (): void {
    expect(ScheduleCadence::Monthly->next(new DateTimeImmutable('2026-12-20 00:00:00'), 6, null, 10)->format('Y-m-d'))->toBe('2027-01-10');
});
