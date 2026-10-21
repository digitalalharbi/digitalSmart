<?php

declare(strict_types=1);

use App\Application\Digest\Command\BuildPerformanceDigests;
use App\Domain\Access\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| The performance digests
|--------------------------------------------------------------------------
|
| Built over the real sandbox sync, so the figures in a digest are the analytics layer's own. What is asserted is
| the digest's promises: figures with their change, a best and a weakest platform by a stated rule, action cards,
| one row per person per period, a reader's own scope, a workspace's own morning, and nothing twice.
|
*/

/** @return array<string, mixed> */
function latestPerformanceDigest(SyncedWorkspace $w, string $kind = 'daily_performance'): array
{
    /** @var array<string, mixed> $row */
    $row = (array) DB::table('digests')->where(['workspace_id' => $w->workspaceId(), 'user_id' => $w->scenario->owner->getKey(), 'kind' => $kind])->first();

    /** @var array<string, mixed> $summary */
    $summary = json_decode((string) ($row['summary'] ?? '{}'), true);

    return $summary;
}

/** The first zone in which the local hour is below / at or past nine right now, so the clock itself is the test. */
function zoneWhere(bool $before): string
{
    foreach (['Pacific/Honolulu', 'America/Los_Angeles', 'UTC', 'Asia/Riyadh', 'Asia/Tokyo', 'Pacific/Auckland'] as $zone) {
        $hour = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('G');

        if (($hour < BuildPerformanceDigests::LOCAL_HOUR) === $before) {
            return $zone;
        }
    }

    throw new LogicException('No zone fits at this hour.');
}

describe('what a digest says', function (): void {
    it('carries the headline figures with their change, and a best and weakest platform by one rule', function (): void {
        $w = SyncedWorkspace::default();

        $this->artisan('digests:performance daily_performance')->assertSuccessful();
        $digest = latestPerformanceDigest($w);

        expect($digest['cadence'])->toBe('daily_performance')
            ->and($digest['period']['days'])->toBe(1)
            ->and($digest['figures']['spend']['value'])->toBeGreaterThan(0)
            ->and($digest['figures']['spend'])->toHaveKeys(['change_ratio', 'comparison_value', 'currency'])
            ->and(array_column($digest['platforms'], 'key'))->toEqualCanonicalizing(['meta', 'google_ads'])
            ->and($digest['ranked_by'])->not->toBeNull()
            ->and($digest['best_platform']['label'])->not->toBe($digest['weakest_platform']['label'])
            ->and(count($digest['cards']))->toBeLessThanOrEqual(5)
            ->and($digest['severity'])->toBeIn(['info', 'success', 'attention', 'critical']);
    });

    it('agrees with the analytics page on spend, because it is the same read', function (): void {
        $w = SyncedWorkspace::default();
        $this->artisan('digests:performance weekly_performance')->assertSuccessful();
        $digest = latestPerformanceDigest($w, 'weekly_performance');

        $page = test()->actingAs($w->scenario->owner)
            ->getJson('/api/analytics?preset=7d&metrics[]=spend')
            ->assertOk()
            ->json('data.summary.values.spend.value');

        expect($digest['figures']['spend']['value'])->toBe($page)
            ->and($digest['period']['days'])->toBe(7);
    });

    it('rings the bell once per digest with its severity and a headline payload, and never twice for one period', function (): void {
        $w = SyncedWorkspace::default();

        $this->artisan('digests:performance daily_performance')->assertSuccessful();
        $this->artisan('digests:performance daily_performance')->assertSuccessful();

        $bell = test()->actingAs($w->scenario->owner)->getJson('/api/notifications')->assertOk()->json('data.notifications');
        $digests = array_values(array_filter($bell, static fn (array $n): bool => $n['kind'] === 'performance_digest'));

        expect($digests)->toHaveCount(1)
            ->and($digests[0]['category'])->toBe('performance')
            ->and($digests[0]['payload']['cadence'])->toBe('daily_performance')
            ->and($digests[0]['payload']['spend']['value'])->toBeGreaterThan(0)
            ->and($digests[0]['severity'])->toBeIn(['info', 'success', 'attention', 'critical'])
            ->and(DB::table('digests')->where('kind', 'daily_performance')->where('user_id', $w->scenario->owner->getKey())->count())->toBe(1);
    });

    it('offers recommendations only weekly and monthly, never more than three, each with its evidence', function (): void {
        $w = SyncedWorkspace::default();

        $this->artisan('digests:performance daily_performance')->assertSuccessful();
        $this->artisan('digests:performance weekly_performance')->assertSuccessful();

        $daily = latestPerformanceDigest($w);
        $weekly = latestPerformanceDigest($w, 'weekly_performance');

        expect($daily['recommendations'])->toBe([])
            ->and(count($weekly['recommendations']))->toBeLessThanOrEqual(3);

        foreach ($weekly['recommendations'] as $recommendation) {
            expect($recommendation)->toHaveKeys(['action', 'subject', 'evidence']);
        }
    });
});

describe('who gets one', function (): void {
    it('composes in the reader\'s own scope: a buyer on no project gets nothing, a creative gets no figures', function (): void {
        $w = SyncedWorkspace::default();
        $buyer = $w->scenario->addMember(SystemRole::MediaBuyer);
        $creative = $w->scenario->addMember(SystemRole::Creative);

        $this->artisan('digests:performance daily_performance')->assertSuccessful();

        expect(DB::table('digests')->where('user_id', $buyer->getKey())->count())->toBe(0)
            ->and(DB::table('digests')->where('user_id', $creative->getKey())->count())->toBe(0)
            ->and(DB::table('digests')->where('user_id', $w->scenario->owner->getKey())->count())->toBe(1);
    });

    it('composes the budget part only for somebody who may read budgets', function (): void {
        $w = SyncedWorkspace::default();

        $this->artisan('digests:performance daily_performance')->assertSuccessful();

        expect(latestPerformanceDigest($w)['budget'])->toBeArray()->toHaveKeys(['budgets', 'at_risk', 'worst']);
    });
});

describe('when it lands', function (): void {
    it('is due once the workspace\'s own morning reaches nine, not UTC\'s', function (): void {
        $w = SyncedWorkspace::default();

        DB::table('workspaces')->where('id', $w->workspaceId())->update(['timezone' => zoneWhere(before: true)]);
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe(0);

        DB::table('workspaces')->where('id', $w->workspaceId())->update(['timezone' => zoneWhere(before: false)]);
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe(1);

        /* An hour later the scheduler asks again; the period is done and nothing is rebuilt. */
        $this->artisan('digests:performance')->assertSuccessful();
        expect(DB::table('digests')->where('kind', 'daily_performance')->count())->toBe(1);
    });

    it('builds the weekly digest only on the workspace\'s own Sunday', function (): void {
        $w = SyncedWorkspace::default();
        $zone = zoneWhere(before: false);
        DB::table('workspaces')->where('id', $w->workspaceId())->update(['timezone' => $zone]);
        $isSunday = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('w') === 0;

        $this->artisan('digests:performance')->assertSuccessful();

        expect(DB::table('digests')->where('kind', 'weekly_performance')->count())->toBe($isSunday ? 1 : 0);
    });

    it('refuses a digest kind that is not a performance one, and reads last month as a calendar month', function (): void {
        SyncedWorkspace::default();

        $this->artisan('digests:performance morning_campaigns')->assertFailed();
        $this->artisan('digests:performance monthly_performance')->assertSuccessful();
    });
});
