<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
|
| Three commands existed and nothing ran any of them, which is the kind of gap no other test can see: each
| command's own tests pass, and the product still goes stale. This pins the schedule itself.
|
*/

/** @return list<Event> */
function scheduledEvents(): array
{
    return array_values(app(Schedule::class)->events());
}

function scheduledCommand(string $name): Event
{
    foreach (scheduledEvents() as $event) {
        if (str_contains((string) $event->command, $name)) {
            return $event;
        }
    }

    throw new RuntimeException(sprintf('Nothing in the schedule runs "%s".', $name));
}

it('keeps the performance figures current', function (): void {
    expect(scheduledCommand('advertising:sync')->expression)->toBe('0 * * * *');
});

/*
 * Nightly, not hourly. Webhooks carry the day's orders within seconds; this catches what they dropped, and
 * each adapter's restatement window is measured in days — an hourly pass would spend a platform's rate limit
 * re-reading the same window to find the same nothing.
 */
it('reconciles the stores once a night', function (): void {
    expect(scheduledCommand('commerce:sync')->expression)->toBe('30 3 * * *');
});

it('brings every workspace’s roles in line with the code', function (): void {
    expect(scheduledCommand('access:sync')->expression)->toBe('0 3 * * *');
});

/*
 * Each of the three queues work rather than doing it, so a run starting while the previous one is still
 * dispatching would double every job in flight. The per-account and per-store jobs are unique, so the
 * duplicates would be dropped silently and nobody would learn the schedule was too tight.
 */
it('never lets a pass overlap its own previous run', function (): void {
    foreach (['advertising:sync', 'commerce:sync', 'access:sync'] as $command) {
        expect(scheduledCommand($command)->withoutOverlapping)->toBeTrue();
    }
});
