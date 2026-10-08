<?php

declare(strict_types=1);

use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\WorkLedger;
use App\Application\Shared\DTO\WorkBacklog;
use App\Domain\Shared\ValueObject\ServiceStatus;
use App\Infrastructure\System\FailedWorkHealthProbe;
use App\Infrastructure\System\QueueHealthProbe;
use Illuminate\Config\Repository as ConfigRepository;

/*
|--------------------------------------------------------------------------
| What the queue probes are for
|--------------------------------------------------------------------------
|
| Reachability is the easy half, and the half every health endpoint already answers. These tests are about the
| other half: **whether the work is actually happening.** Two failures hide inside a healthy-looking queue —
| a reachable queue nothing is consuming, and an empty queue that is empty because the jobs gave up — and both
| end the same way, with a figure on a client's screen that nobody refreshed.
|
*/

function ledgerOf(WorkBacklog $backlog, ?WorkBacklog $failures = null): WorkLedger
{
    return new class($backlog, $failures ?? WorkBacklog::empty()) implements WorkLedger
    {
        public function __construct(
            private WorkBacklog $backlog,
            private WorkBacklog $failures,
        ) {}

        public function backlog(): WorkBacklog
        {
            return $this->backlog;
        }

        public function failures(): WorkBacklog
        {
            return $this->failures;
        }
    };
}

function clockAt(string $moment): Clock
{
    return new class($moment) implements Clock
    {
        public function __construct(private string $moment) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->moment);
        }
    };
}

function queueProbe(WorkBacklog $backlog, string $now = '2026-10-08 12:00:00'): App\Application\Shared\DTO\ServiceHealth
{
    return (new QueueHealthProbe(
        ledgerOf($backlog),
        new ConfigRepository(['queue' => ['default' => 'database']]),
        clockAt($now),
    ))->probe();
}

describe('a queue nothing is consuming', function (): void {
    it('is degraded on a depth of one, if that one has been waiting', function (): void {
        $health = queueProbe(WorkBacklog::of(1, new DateTimeImmutable('2026-10-08 11:40:00')));

        /*
         * The defect this replaced: the probe reported **ok** for any depth under a thousand, so a worker that
         * died with three jobs queued was indistinguishable from an idle, healthy queue. Depth was never the
         * signal — age is. One job that has waited twenty minutes means nothing is consuming the queue.
         */
        expect($health->status)->toBe(ServiceStatus::Degraded);
        expect($health->detail)->toContain('nothing appears to be consuming it');
    });

    it('stays ok while a job is merely young', function (): void {
        $health = queueProbe(WorkBacklog::of(12, new DateTimeImmutable('2026-10-08 11:59:00')));

        /*
         * A queue being used is not a queue in trouble, and a probe that cried wolf at a job a minute old is a
         * probe somebody turns off — which is how the real alert gets missed.
         */
        expect($health->status)->toBe(ServiceStatus::Ok);
        expect($health->detail)->toContain('12 queued');
    });

    it('is degraded on a deep backlog even while it moves', function (): void {
        $health = queueProbe(WorkBacklog::of(5_000, new DateTimeImmutable('2026-10-08 11:59:30')));

        // Consuming, and not keeping up. A different problem with the same consequence: stale figures.
        expect($health->status)->toBe(ServiceStatus::Degraded);
        expect($health->detail)->toContain('backlog is 5000 jobs');
    });

    it('says when the driver does not let it look', function (): void {
        $health = queueProbe(WorkBacklog::countOnly(7));

        /*
         * The honesty rule applied to the product's own plumbing. Redis publishes a depth and not an age, and a
         * probe that reported "nothing stalled" after failing to look would be making a claim it cannot support.
         */
        expect($health->status)->toBe(ServiceStatus::Ok);
        expect($health->detail)->toContain('does not publish job ages');
        expect($health->detail)->toContain('a stalled worker is not detected here');
    });

    it('is ok, and says so, on an empty queue', function (): void {
        expect(queueProbe(WorkBacklog::empty())->status)->toBe(ServiceStatus::Ok);
        expect(queueProbe(WorkBacklog::empty())->detail)->toContain('nothing queued');
    });
});

describe('work that gave up', function (): void {
    it('is degraded on the first failure, not the tenth', function (): void {
        $health = (new FailedWorkHealthProbe(
            ledgerOf(WorkBacklog::empty(), WorkBacklog::of(1, new DateTimeImmutable('2026-10-06 12:00:00'))),
            clockAt('2026-10-08 12:00:00'),
        ))->probe();

        /*
         * No threshold, deliberately. A failed sync leaves yesterday's spend on the dashboard and a failed
         * webhook leaves an order out of a client's revenue — the single quiet failure is the one worth
         * reading, and a probe that stayed green until ten had piled up would hide exactly that.
         */
        expect($health->status)->toBe(ServiceStatus::Degraded);
        expect($health->detail)->toContain('2 day(s) ago');
        expect($health->detail)->toContain('work that did not happen');
    });

    it('is ok when nothing has given up', function (): void {
        $health = (new FailedWorkHealthProbe(ledgerOf(WorkBacklog::empty()), clockAt('2026-10-08 12:00:00')))
            ->probe();

        // A good state said out loud, so an empty panel cannot be mistaken for a probe that failed to run.
        expect($health->status)->toBe(ServiceStatus::Ok);
        expect($health->detail)->toBe('none');
    });

    it('does not claim to know when, where the store does not say', function (): void {
        $health = (new FailedWorkHealthProbe(
            ledgerOf(WorkBacklog::empty(), WorkBacklog::countOnly(3)),
            clockAt('2026-10-08 12:00:00'),
        ))->probe();

        expect($health->status)->toBe(ServiceStatus::Degraded);
        expect($health->detail)->toContain('does not publish when');
    });
});

describe('what a backlog means', function (): void {
    it('tells an empty backlog apart from an unreadable age', function (): void {
        /*
         * Both have a null `oldest`, and conflating them is the mistake this flag exists to prevent: one means
         * there is nothing waiting, the other means nobody looked.
         */
        expect(WorkBacklog::empty()->ageIsKnown)->toBeTrue();
        expect(WorkBacklog::countOnly(4)->ageIsKnown)->toBeFalse();
        expect(WorkBacklog::countOnly(4)->isEmpty())->toBeFalse();
    });

    it('never reports a negative wait', function (): void {
        // Clock skew between a worker host and the API host is ordinary, and "waited -3 seconds" is not a figure.
        $backlog = WorkBacklog::of(1, new DateTimeImmutable('2026-10-08 12:00:05'));

        expect($backlog->waitedSeconds(new DateTimeImmutable('2026-10-08 12:00:00')))->toBe(0);
    });
});
