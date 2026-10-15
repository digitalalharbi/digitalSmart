<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Domain\Integration\Sync\PlannedSync;
use App\Domain\Integration\Sync\SyncCandidate;
use App\Domain\Integration\Sync\SyncClass;
use App\Domain\Integration\Sync\SyncPlanner;

$now = new DateTimeImmutable('2026-06-15T12:00:00+00:00');

function candidate(
    string $id,
    string $client = 'client-a',
    ?string $lastSuccess = '2026-06-15T11:00:00+00:00',
    int $active = 3,
    bool $live = true,
    Provider $provider = Provider::Meta,
): SyncCandidate {
    return new SyncCandidate('ws', $id, $provider, $client,
        $lastSuccess === null ? null : new DateTimeImmutable($lastSuccess), $active, $live);
}

/**
 * @param  list<PlannedSync>  $plan
 * @return list<string>
 */
function ids(array $plan): array
{
    return array_map(static fn (PlannedSync $p): string => $p->candidate->externalAccountId, $plan);
}

describe('adaptive cadence', function () use ($now): void {
    it('leaves a live account alone while its figures are fresh', function () use ($now): void {
        $plan = (new SyncPlanner)->plan([candidate('a', lastSuccess: '2026-06-15T11:30:00+00:00')], $now);

        expect($plan)->toBe([]);
    });

    it('reads a live account once an hour has passed', function () use ($now): void {
        $plan = (new SyncPlanner)->plan([candidate('a', lastSuccess: '2026-06-15T10:30:00+00:00')], $now);

        expect($plan[0]->class)->toBe(SyncClass::Live)->and($plan[0]->class->queue())->toBe('sync');
    });

    /* A dormant account pulled at the live rate spends a rate limit re-reading the same nothing. */
    it('reads a dormant account on a six-hour cadence', function () use ($now): void {
        $fresh = candidate('a', lastSuccess: '2026-06-15T08:00:00+00:00', active: 0);
        $due = candidate('b', lastSuccess: '2026-06-15T05:00:00+00:00', active: 0);

        $plan = (new SyncPlanner)->plan([$fresh, $due], $now);

        expect(ids($plan))->toBe(['b'])
            ->and($plan[0]->class)->toBe(SyncClass::Dormant)
            ->and($plan[0]->class->queue())->toBe('sync-low');
    });

    it('spends nothing recurring on an account whose projects are all finished, unless forced', function () use ($now): void {
        $done = candidate('a', lastSuccess: '2026-06-01T00:00:00+00:00', live: false);

        expect((new SyncPlanner)->plan([$done], $now))->toBe([])
            ->and(ids((new SyncPlanner)->plan([$done], $now, force: true)))->toBe(['a']);
    });

    it('gives a brand-new account its first read at once instead of waiting out a cadence', function () use ($now): void {
        $plan = (new SyncPlanner)->plan([candidate('new', lastSuccess: null, active: 0)], $now);

        expect($plan[0]->class)->toBe(SyncClass::Live);
    });
});

describe('recovery', function () use ($now): void {
    it('treats an active account silent for three cadences as stuck, not slow', function () use ($now): void {
        $stale = candidate('stale', lastSuccess: '2026-06-15T07:00:00+00:00');
        $routine = candidate('routine', client: 'client-b', lastSuccess: '2026-06-15T10:30:00+00:00');

        $plan = (new SyncPlanner)->plan([$routine, $stale], $now);

        expect(ids($plan))->toBe(['stale', 'routine'])
            ->and($plan[0]->class)->toBe(SyncClass::Recovery)
            ->and($plan[0]->class->queue())->toBe('sync-recovery');
    });
});

describe('fairness', function () use ($now): void {
    /*
     * The acceptance case from the constitution: client A has fifty accounts, client B has two. B must be
     * reached in the first round, not after A's whole backlog.
     */
    it('does not let one large client queue the rest of the agency behind it', function () use ($now): void {
        $big = [];

        for ($i = 1; $i <= 50; $i++) {
            $big[] = candidate(sprintf('a%02d', $i), client: 'client-a', lastSuccess: '2026-06-15T09:00:00+00:00');
        }

        $small = [
            candidate('b1', client: 'client-b', lastSuccess: '2026-06-15T09:00:00+00:00'),
            candidate('b2', client: 'client-b', lastSuccess: '2026-06-15T09:00:00+00:00'),
        ];

        $order = ids((new SyncPlanner)->plan([...$big, ...$small], $now));

        expect(array_search('b1', $order, true))->toBeLessThan(2)
            ->and(array_search('b2', $order, true))->toBeLessThan(4)
            ->and(count($order))->toBe(52);
    });

    it('lets the group with the most urgent work lead each round', function () use ($now): void {
        $order = ids((new SyncPlanner)->plan([
            candidate('a1', client: 'client-a', lastSuccess: '2026-06-15T10:30:00+00:00'),
            candidate('b1', client: 'client-b', lastSuccess: '2026-06-15T03:00:00+00:00'),
        ], $now));

        expect($order[0])->toBe('b1');
    });
});

describe('throttle and backfill', function () use ($now): void {
    it('releases one provider’s jobs in bursts rather than all at once', function () use ($now): void {
        $many = [];

        for ($i = 1; $i <= 12; $i++) {
            $many[] = candidate(sprintf('m%02d', $i), client: 'c'.$i, lastSuccess: null);
        }

        $delays = array_map(static fn (PlannedSync $p): int => $p->delaySeconds, (new SyncPlanner)->plan($many, $now));

        expect($delays[0])->toBe(0)
            ->and($delays[4])->toBe(0)
            ->and($delays[5])->toBe(SyncPlanner::BURST_SPACING_SECONDS)
            ->and($delays[11])->toBe(2 * SyncPlanner::BURST_SPACING_SECONDS);
    });

    it('does not throttle one provider against another', function () use ($now): void {
        $plan = (new SyncPlanner)->plan([
            candidate('m', client: 'c1', lastSuccess: null, provider: Provider::Meta),
            candidate('g', client: 'c2', lastSuccess: null, provider: Provider::GoogleAds),
        ], $now);

        expect(array_map(static fn (PlannedSync $p): int => $p->delaySeconds, $plan))->toBe([0, 0]);
    });

    it('puts a requested window behind everything current', function () use ($now): void {
        $plan = (new SyncPlanner)->plan([candidate('a', lastSuccess: '2026-06-15T11:59:00+00:00')], $now, backfill: true);

        expect($plan[0]->class)->toBe(SyncClass::Backfill)
            ->and($plan[0]->class->queue())->toBe('sync-backfill')
            ->and(SyncClass::Backfill->priority())->toBeGreaterThan(SyncClass::Dormant->priority());
    });
});
