<?php

declare(strict_types=1);

use App\Application\Shared\Contract\WorkLedger;
use App\Domain\Shared\ValueObject\ServiceStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The ledger against the real tables
|--------------------------------------------------------------------------
|
| The probes are unit-tested against a fake ledger, which proves the judgement and not the SQL. These read the
| queue's own tables, because the two figures that matter — how many are waiting and how long the oldest has
| waited — come out of columns whose units are easy to get wrong: `jobs.available_at` is a Unix integer and
| `failed_jobs.failed_at` is a timestamp.
|
*/

it('reads the depth and the oldest wait out of the jobs table', function (): void {
    config()->set('queue.default', 'database');

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->subMinutes(20)->getTimestamp(),
            'created_at' => now()->subMinutes(20)->getTimestamp(),
        ],
        [
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->subMinute()->getTimestamp(),
            'created_at' => now()->subMinute()->getTimestamp(),
        ],
    ]);

    $backlog = app(WorkLedger::class)->backlog();

    expect($backlog->count)->toBe(2);
    expect($backlog->ageIsKnown)->toBeTrue();

    // The oldest, not the newest and not an average: the question is how long anything has been stuck.
    expect($backlog->waitedSeconds(new DateTimeImmutable))->toBeGreaterThanOrEqual(1_190);
});

it('reports an empty jobs table as nothing waiting rather than an unknown age', function (): void {
    config()->set('queue.default', 'database');

    $backlog = app(WorkLedger::class)->backlog();

    expect($backlog->isEmpty())->toBeTrue();
    expect($backlog->oldest)->toBeNull();
    /* The distinction the flag exists for: nothing is waiting, as opposed to nobody looked. */
    expect($backlog->ageIsKnown)->toBeTrue();
});

it('answers depth only on a driver that publishes no ages', function (): void {
    /*
     * `sync` is the test environment's own connection and has no table at all. The honest answer is a count
     * with the age withheld — not a silent zero, which would read as "nothing has been waiting".
     */
    config()->set('queue.default', 'sync');

    expect(app(WorkLedger::class)->backlog()->ageIsKnown)->toBeFalse();
});

it('reads failures out of the failed-jobs table and degrades the endpoint', function (): void {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Provider request failed after 3 attempts.',
        'failed_at' => now()->subDays(2),
    ]);

    $failures = app(WorkLedger::class)->failures();

    expect($failures->count)->toBe(1);
    expect($failures->waitedSeconds(new DateTimeImmutable))->toBeGreaterThan(172_000);

    /*
     * And it reaches the endpoint. A failed job is work somebody believes happened, so the report says so out
     * loud — while staying 200, because abandoned work is not an outage of the platform and answering 503 here
     * would take the API offline behind a load balancer for a sync that needs retrying.
     */
    $response = $this->getJson('/api/health')->assertOk();

    /** @var list<array{name: string, status: string, detail: string|null}> $services */
    $services = $response->json('data.services');
    $failed = array_values(array_filter($services, static fn (array $s): bool => $s['name'] === 'failed_jobs'));

    expect($failed[0]['status'])->toBe(ServiceStatus::Degraded->value);
    expect($failed[0]['detail'])->toContain('work that did not happen');
    expect($response->json('data.status'))->toBe(ServiceStatus::Degraded->value);
});

it('keeps no failed store as nothing to report rather than something wrong', function (): void {
    config()->set('queue.failed.driver', 'null');

    /* A legitimate setting. An environment that keeps no failed jobs has nothing to say, not a fault. */
    expect(app(WorkLedger::class)->failures()->isEmpty())->toBeTrue();
});
