<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The schedule
|--------------------------------------------------------------------------
|
| Three commands existed and nothing ran any of them. Each is written to be re-runnable and each answers a
| question the product otherwise answers wrongly over time:
|
|  - **`advertising:sync`** keeps performance figures current, and re-reads a lookback window because
|    conversions keep landing against past dates. Without it a dashboard is as old as the last person who
|    pressed a button.
|  - **`commerce:sync`** is the reconciliation read webhooks need. One dropped delivery, one deploy during a
|    busy hour, one platform incident, and a client's revenue is quietly short for that window with nothing on
|    any screen to indicate it.
|  - **`access:sync`** brings every workspace's system roles in line with the code. A deploy that adds a
|    permission otherwise reaches new workspaces immediately and existing ones not at all.
|
| `withoutOverlapping` on all three: each queues work rather than doing it, but a run that starts while the
| previous one is still dispatching would double every job in flight — and the per-account and per-store jobs
| are unique, so the duplicates would be dropped silently and the operator would never learn the schedule was
| too tight. `runInBackground` keeps one slow dispatch from delaying the others.
|
| Times are the host's, which `APP_TIMEZONE` sets. The two provider passes run in the small hours of the
| agency's day, when a rate limit spent on a backfill costs nobody their morning.
*/
Schedule::command('advertising:sync')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Nightly rather than hourly. Webhooks carry the day's orders within seconds; this exists to catch what they
 * dropped, and the restatement window each adapter publishes is measured in days — so an hourly pass would
 * spend a platform's rate limit re-reading the same window twenty-four times to find the same nothing.
 */
Schedule::command('commerce:sync')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Daily, and deliberately not only on deploy: a workspace created between deploys gets its roles at
 * provision time, and one that existed before a permission was added needs this pass to see it.
 */
Schedule::command('access:sync')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Digests: one summary per person instead of N alerts.
 *
 * Staggered after the sync passes they summarise. Morning campaigns land before the working day; budgets
 * daily; the project digest on Mondays, when the week's waiting work is worth reading as a whole.
 */
Schedule::command('digests:build morning_campaigns')->dailyAt('06:30')->withoutOverlapping()->runInBackground();
Schedule::command('digests:build daily_budget')->dailyAt('07:00')->withoutOverlapping()->runInBackground();
Schedule::command('digests:build weekly_projects')->weeklyOn(1, '07:30')->withoutOverlapping()->runInBackground();
Schedule::command('digests:build monthly_executive')->monthlyOn(1, '08:00')->withoutOverlapping()->runInBackground();

/* Exports are kept a week. The file goes; the row stays as the record of having asked. */
Schedule::command('exports:prune')->dailyAt('04:00')->withoutOverlapping()->runInBackground();

/* Hourly: schedules carry an hour, so the finest useful grain is one hour. */
Schedule::command('reports:run-schedules')->hourly()->withoutOverlapping()->runInBackground();

/*
 * The routines. Agents propose and never decide, so running them often costs a person nothing but a card.
 *
 * The daily performance review runs before the working day in the agency's own time zone, so the Today list is
 * ready when people sit down. The budget guardian looks every two hours. Expiry rides on both: a proposal nobody
 * decided stops asking once the figures it rested on have moved.
 */
Schedule::command('agents:run analyst')->dailyAt('08:45')->timezone('Asia/Riyadh')->withoutOverlapping()->runInBackground();
Schedule::command('agents:run finance')->everyTwoHours()->withoutOverlapping()->runInBackground();
/* Creative review twice a week, before the Sunday and Wednesday stand-ups. */
Schedule::command('agents:run creative')->days([0, 3])->at('09:00')->timezone('Asia/Riyadh')->withoutOverlapping()->runInBackground();
Schedule::command('agents:run leads')->dailyAt('10:00')->timezone('Asia/Riyadh')->withoutOverlapping()->runInBackground();
/* The weekly client brief, ready to review on Thursday afternoon before the weekend. */
Schedule::command('agents:run reports')->weeklyOn(4, '14:00')->timezone('Asia/Riyadh')->withoutOverlapping()->runInBackground();
Schedule::command('agents:run operations')->dailyAt('08:30')->timezone('Asia/Riyadh')->withoutOverlapping()->runInBackground();
