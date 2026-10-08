<?php

declare(strict_types=1);

use App\Domain\Shared\ValueObject\DateWindow;

/*
|--------------------------------------------------------------------------
| Splitting a range into windows no provider will refuse
|--------------------------------------------------------------------------
|
| Every provider publishes a longest range it answers in one request. The arithmetic here is boring and the
| off-by-one is not: a window that starts on the day the last one ended reads that day twice, and one that starts
| two days later loses a day from a client's report with nothing to show it happened.
|
*/

/**
 * @param  list<DateWindow>  $windows
 * @return list<array{string, string}>
 */
function asPairs(array $windows): array
{
    return array_map(
        static fn (DateWindow $window): array => [
            $window->from->format('Y-m-d'),
            $window->until->format('Y-m-d'),
        ],
        $windows,
    );
}

it('leaves a range shorter than one window alone', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-07'),
        92,
    );

    expect(asPairs($windows))->toBe([['2026-10-01', '2026-10-07']]);
});

/*
 * The off-by-one, pinned. Inclusive at both ends, because that is how every adapter filters a date: seven days
 * is seven dates and the next window starts on the eighth, not the seventh.
 */
it('makes consecutive windows that neither overlap nor skip a day', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-21'),
        7,
    );

    expect(asPairs($windows))->toBe([
        ['2026-10-01', '2026-10-07'],
        ['2026-10-08', '2026-10-14'],
        ['2026-10-15', '2026-10-21'],
    ]);
});

it('ends the last window on the day asked for, not on the window boundary', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-10'),
        7,
    );

    expect(asPairs($windows))->toBe([
        ['2026-10-01', '2026-10-07'],
        ['2026-10-08', '2026-10-10'],
    ]);
});

it('splits a range of exactly one window into one', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-07'),
        7,
    );

    expect($windows)->toHaveCount(1);
});

it('treats a single day as one window', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-01'),
        new DateTimeImmutable('2026-10-01'),
        92,
    );

    expect(asPairs($windows))->toBe([['2026-10-01', '2026-10-01']]);
});

/*
 * A degenerate range still produces one window, deliberately. A sync that returned none would leave its journal
 * with no record of having been asked — and afterwards that reads as a sync that never ran rather than one that
 * had nothing to do.
 */
it('still asks once for a range that ends before it starts', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-10-10'),
        new DateTimeImmutable('2026-10-01'),
        92,
    );

    expect(asPairs($windows))->toBe([['2026-10-10', '2026-10-01']]);
});

it('never produces a window of zero days, whatever the limit says', function (): void {
    foreach ([0, -1, -92] as $nonsense) {
        $windows = DateWindow::split(
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-03'),
            $nonsense,
        );

        expect(asPairs($windows))->toBe([
            ['2026-10-01', '2026-10-01'],
            ['2026-10-02', '2026-10-02'],
            ['2026-10-03', '2026-10-03'],
        ]);
    }
});

it('covers a year without losing or repeating a day', function (): void {
    $windows = DateWindow::split(
        new DateTimeImmutable('2026-01-01'),
        new DateTimeImmutable('2026-12-31'),
        92,
    );

    $days = [];

    foreach ($windows as $window) {
        $cursor = $window->from;

        while ($cursor <= $window->until) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    }

    expect($windows)->toHaveCount(4)
        ->and($days)->toHaveCount(365)
        ->and(array_unique($days))->toHaveCount(365)
        ->and($days[0])->toBe('2026-01-01')
        ->and($days[364])->toBe('2026-12-31');
});
