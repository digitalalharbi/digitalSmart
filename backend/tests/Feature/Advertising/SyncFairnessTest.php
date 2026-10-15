<?php

declare(strict_types=1);

use App\Interface\Queue\SyncBoundAccountJob;
use Database\Seeders\AgencyScaleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The sync policy, through the command a scheduler actually runs.
 *
 * The planner's rules are exhaustively covered as a pure function. What is asserted here is the wiring:
 * that the command reads the agency once, hands the planner real candidates, and puts each job in the
 * queue the plan named — and that a failure in one job leaves the others standing.
 */
function seedSmallAgency(): void
{
    config([
        'agency_scale.clients' => 4,
        'agency_scale.projects_per_client' => 1,
        'agency_scale.accounts' => 8,
        'agency_scale.campaigns' => 16,
        'agency_scale.days' => 2,
    ]);

    app(AgencyScaleSeeder::class)->run();
}

function queueFake(): Illuminate\Support\Testing\Fakes\QueueFake
{
    $fake = Queue::getFacadeRoot();
    assert($fake instanceof Illuminate\Support\Testing\Fakes\QueueFake);

    return $fake;
}

/** @return list<array{queue: string, account: string, delay: mixed}> */
function pushedJobs(): array
{
    $jobs = [];

    foreach (queueFake()->pushed(SyncBoundAccountJob::class) as $job) {
        $jobs[] = [
            'queue' => (string) $job->queue,
            'account' => (string) (new ReflectionProperty($job, 'externalAccountId'))->getValue($job),
            'delay' => $job->delay,
        ];
    }

    return $jobs;
}

it('queues every live account on the live queue the first time, and leaves finished projects alone', function (): void {
    Queue::fake();
    seedSmallAgency();

    /* One account's projects are all finished: history is kept, nothing recurring is spent on it. */
    $archived = DB::table('project_integration_bindings')->orderBy('id')->value('external_account_id');
    $projects = DB::table('project_integration_bindings')->where('external_account_id', $archived)->pluck('project_id');
    DB::table('projects')->whereIn('id', $projects)->update(['status' => 'archived']);

    $others = DB::table('project_integration_bindings')
        ->where('external_account_id', $archived)->count();
    expect($others)->toBeGreaterThan(0);

    $this->artisan('advertising:sync')->assertSuccessful();

    $jobs = pushedJobs();
    $stillLive = DB::table('external_accounts as a')
        ->whereExists(static fn ($q) => $q->from('project_integration_bindings as b')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->whereColumn('b.external_account_id', 'a.id')
            ->whereIn('p.status', ['planning', 'active', 'paused']))
        ->count();

    expect(array_unique(array_column($jobs, 'queue')))->toBe(['sync'])
        ->and(count($jobs))->toBe($stillLive)
        ->and(array_column($jobs, 'account'))->not->toContain($archived);
});

it('puts a requested window on the backfill queue, behind everything current', function (): void {
    Queue::fake();
    seedSmallAgency();

    $this->artisan('advertising:sync', ['--since' => '2026-01-01', '--until' => '2026-01-31'])->assertSuccessful();

    expect(array_unique(array_column(pushedJobs(), 'queue')))->toBe(['sync-backfill']);
});

it('does not push an account that was read a few minutes ago', function (): void {
    Queue::fake();
    seedSmallAgency();

    $first = DB::table('external_accounts')->orderBy('id')->first();
    expect($first)->not->toBeNull();
    assert($first instanceof stdClass);
    DB::table('sync_cursors')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(),
        'workspace_id' => $first->workspace_id,
        'external_account_id' => $first->id,
        'kind' => 'insights',
        'last_success_at' => now()->subMinutes(5),
        'consecutive_failures' => 0,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('advertising:sync')->assertSuccessful();

    expect(array_column(pushedJobs(), 'account'))->not->toContain($first->id);
});

/*
 * Failure isolation, at the granularity the queue actually provides: one job per account. A worker runs
 * each in its own call and a throw in one is that job's failure — so what must hold is that the others,
 * run through the same code, complete.
 */
it('lets one failing account fail without stopping the rest of the agency', function (): void {
    Queue::fake();
    seedSmallAgency();
    $this->artisan('advertising:sync')->assertSuccessful();

    $jobs = queueFake()->pushed(SyncBoundAccountJob::class)->all();
    expect(count($jobs))->toBeGreaterThan(2);

    $workspace = (string) DB::table('workspaces')->value('id');
    $escaped = 0;

    foreach ($jobs as $index => $job) {
        /* The first account "disappears" mid-flight, as a deleted or revoked one would. */
        $target = $index === 0
            ? new SyncBoundAccountJob($workspace, '00000000-0000-7000-8000-000000000000')
            : $job;

        try {
            app()->call($target->handle(...));
        } catch (Throwable) {
            /* A worker treats a throw as this job's failure and takes the next one. Counting, not stopping. */
            $escaped++;
        }
    }

    /*
     * Whether the bad job threw or returned a failed outcome, every other account was read: the journal
     * holds a run for each of them, and one account's trouble did not spend anybody else's turn.
     */
    $read = DB::table('sync_runs')->distinct()->count('external_account_id');

    expect($escaped)->toBeLessThanOrEqual(1)
        ->and($read)->toBe(count($jobs) - 1);
});
