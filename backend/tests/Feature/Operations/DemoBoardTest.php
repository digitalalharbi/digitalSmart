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

it('seeds an approval queue with approved work nobody has carried out', function (): void {
    test()->seed(DemoWorkspaceSeeder::class);

    $requests = DB::table('approval_requests')->get();

    expect($requests)->toHaveCount(5);

    $byState = $requests->groupBy('state')->map->count();

    expect($byState['pending'] ?? 0)->toBe(2);
    expect($byState['approved'] ?? 0)->toBe(2);
    expect($byState['declined'] ?? 0)->toBe(1);

    /*
     * The shape that makes the module's point visible: one approved change carried out, and one still waiting
     * on somebody nine days later. A queue where everything was done the same day would demonstrate nothing.
     */
    $awaiting = $requests->where('enactment', 'awaiting_hand');
    expect($awaiting)->toHaveCount(1);
    expect($requests->where('enactment', 'confirmed'))->toHaveCount(1);

    // Every decision names the proposal it read. A CHECK requires it; this asserts the seeder honours it.
    foreach ($requests->whereIn('state', ['approved', 'declined']) as $decided) {
        expect($decided->decided_fingerprint)->toBe($decided->proposal_fingerprint);
        expect($decided->decided_at)->not->toBeNull();
    }

    expect($requests->firstWhere('state', 'declined')?->decision_reason)->not->toBeNull();
});

it('seeds an intake queue whose shape shows both halves of why a queue is slow', function (): void {
    test()->seed(DemoWorkspaceSeeder::class);

    $requests = DB::table('client_requests')->get();

    expect($requests)->toHaveCount(7);

    $byState = $requests->groupBy('state')->map->count();

    expect($byState['needs_information'] ?? 0)->toBe(1);
    expect($byState['declined'] ?? 0)->toBe(1);
    expect($byState['delivered'] ?? 0)->toBe(1);

    /*
     * The three shapes the module exists to publish: a client nobody has answered, accepted work with no promised
     * date, and an open question — which is the one kind that produces no task.
     */
    expect($requests->whereNull('first_answered_at'))->toHaveCount(2);
    expect($requests->where('state', 'accepted')->whereNull('promised_at'))->toHaveCount(1);
    expect($requests->where('kind', 'question')->whereIn('state', ['submitted']))->toHaveCount(1);

    // A decline carries the reason the client is shown, and a request for information says what is needed.
    expect($requests->firstWhere('state', 'declined')?->declined_reason)->not->toBeNull();
    expect($requests->firstWhere('state', 'needs_information')?->needed_information)->not->toBeNull();

    // And a promised date carries the person who promised it, which a CHECK also requires.
    $promised = $requests->whereNotNull('promised_at')->first();

    expect($promised?->promised_by)->not->toBeNull();
});
