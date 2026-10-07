<?php

declare(strict_types=1);

use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The demo board
|--------------------------------------------------------------------------
|
| The demo workspace is built through the real use cases, so this test is really asking whether those rules
| still hold end to end: a blocked task carries its reason, a cancelled one carries its own, done work carries
| the moment it finished, and the board deliberately contains undated and unowned work so the honesty figures
| beside it have something to be honest about.
|
| It exists because the seeder is the one path nothing else covers and the first thing a person runs.
|
*/

it('seeds a board whose shape makes the honesty figures non-trivial', function (): void {
    test()->seed(DemoWorkspaceSeeder::class);

    $tasks = DB::table('tasks')->get();

    expect($tasks)->toHaveCount(7);

    $byState = $tasks->groupBy('state')->map->count();

    expect($byState['blocked'] ?? 0)->toBe(1);
    expect($byState['done'] ?? 0)->toBe(1);
    expect($byState['cancelled'] ?? 0)->toBe(1);

    $blocked = $tasks->firstWhere('state', 'blocked');
    $cancelled = $tasks->firstWhere('state', 'cancelled');
    $done = $tasks->firstWhere('state', 'done');

    // The rules the schema enforces, exercised by the path a person actually runs.
    expect($blocked?->blocked_reason)->not->toBeNull();
    expect($cancelled?->cancelled_reason)->not->toBeNull();
    expect($done?->completed_at)->not->toBeNull();

    /*
     * The point of the demo: two tasks with no date and one with nobody on it. A demo of tidy work would
     * demonstrate none of what this module is for.
     */
    expect($tasks->whereNull('due_on')->count())->toBe(2);
    expect($tasks->whereNull('assignee_id')->count())->toBe(1);
});
