<?php

declare(strict_types=1);

use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignHealthPolicy;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CampaignVitals;
use App\Domain\Advertising\EntityStatus;

$now = new DateTimeImmutable('2026-06-15T12:00:00+00:00');

/** @param array<string, mixed> $overrides */
function vitals(array $overrides = []): CampaignVitals
{
    return new CampaignVitals(
        status: $overrides['status'] ?? EntityStatus::Active,
        objective: $overrides['objective'] ?? CampaignObjective::Sales,
        reportedDays: $overrides['reportedDays'] ?? 28,
        spendMinor: array_key_exists('spendMinor', $overrides) ? $overrides['spendMinor'] : 500_000,
        results: array_key_exists('results', $overrides) ? $overrides['results'] : 100.0,
        revenueMinor: array_key_exists('revenueMinor', $overrides) ? $overrides['revenueMinor'] : 2_000_000,
        previousSpendMinor: array_key_exists('previousSpendMinor', $overrides)
            ? $overrides['previousSpendMinor']
            : 500_000,
        previousResults: array_key_exists('previousResults', $overrides)
            ? $overrides['previousResults']
            : 100.0,
        lastSyncedAt: array_key_exists('lastSyncedAt', $overrides)
            ? $overrides['lastSyncedAt']
            : new DateTimeImmutable('2026-06-15T06:00:00+00:00'),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{CampaignHealth, string}
 */
function verdict(array $overrides, DateTimeImmutable $at): array
{
    $result = (new CampaignHealthPolicy)->assess(vitals($overrides), $at);

    return [$result->health, $result->reason];
}

describe('what the platform says comes first', function () use ($now): void {
    it('calls a rejected campaign critical, whatever its figures look like', function () use ($now): void {
        [$health] = verdict(['status' => EntityStatus::Rejected], $now);

        expect($health)->toBe(CampaignHealth::Critical);
    });

    it('calls an active-but-undelivered campaign critical', function () use ($now): void {
        [$health, $reason] = verdict(['status' => EntityStatus::NotDelivering], $now);

        expect($health)->toBe(CampaignHealth::Critical)
            ->and($reason)->toContain('not delivering');
    });

    /*
     * A paused campaign is not a problem. Putting one in the attention queue is how the queue fills with
     * things nobody needs to act on and stops being opened.
     */
    it('treats a paused or finished campaign as inactive rather than as a finding', function () use ($now): void {
        foreach ([EntityStatus::Paused, EntityStatus::Completed, EntityStatus::Archived] as $status) {
            [$health] = verdict(['status' => $status, 'spendMinor' => 0, 'results' => 0.0], $now);

            expect($health)->toBe(CampaignHealth::Inactive);
        }
    });

    it('does not call an unreviewed campaign a problem', function () use ($now): void {
        [$health] = verdict(['status' => EntityStatus::InReview], $now);

        expect($health)->toBe(CampaignHealth::Watch);
    });

    /* Unknown is not healthy. Nothing has been established. */
    it('refuses to read an unknown platform state as healthy', function () use ($now): void {
        [$health] = verdict(['status' => EntityStatus::Unknown], $now);

        expect($health)->toBe(CampaignHealth::InsufficientData);
    });
});

describe('figures that stopped arriving', function () use ($now): void {
    /*
     * The one rule that can call a campaign critical while every number on its row looks healthy.
     * Performance computed from figures that stopped arriving is a statement about the past wearing the
     * clothes of a statement about now.
     */
    it('outranks performance when nothing has synced for two days', function () use ($now): void {
        [$health, $reason] = verdict([
            'lastSyncedAt' => new DateTimeImmutable('2026-06-12T12:00:00+00:00'),
        ], $now);

        expect($health)->toBe(CampaignHealth::Critical)
            ->and($reason)->toContain('out of date');
    });

    it('leaves a campaign that synced this morning alone', function () use ($now): void {
        [$health] = verdict(['lastSyncedAt' => new DateTimeImmutable('2026-06-15T05:00:00+00:00')], $now);

        expect($health)->toBe(CampaignHealth::Healthy);
    });

    /*
     * A campaign that has never synced has not failed to sync — it may have been created an hour ago.
     * The reported-days rule says that more accurately than a staleness rule could.
     */
    it('does not call a never-synced campaign stale', function () use ($now): void {
        [$health] = verdict(['lastSyncedAt' => null, 'reportedDays' => 2], $now);

        expect($health)->toBe(CampaignHealth::InsufficientData);
    });
});

describe('too early to say', function () use ($now): void {
    it('refuses a verdict on fewer than seven reported days', function () use ($now): void {
        [$health, $reason] = verdict(['reportedDays' => 4], $now);

        expect($health)->toBe(CampaignHealth::InsufficientData)
            ->and($reason)->toContain('4 days');
    });

    it('reads a trend once a full week has reported', function () use ($now): void {
        [$health] = verdict(['reportedDays' => 7], $now);

        expect($health)->toBe(CampaignHealth::Healthy);
    });
});

describe('spending with nothing to show', function () use ($now): void {
    it('is the strongest performance finding there is', function () use ($now): void {
        [$health, $reason] = verdict(['results' => 0.0], $now);

        expect($health)->toBe(CampaignHealth::Critical)
            ->and($reason)->toContain('nothing has been recorded');
    });

    /*
     * Null results means nobody reported them. That is a measurement gap, and reading it as zero
     * conversions would turn a tracking problem into a performance accusation.
     */
    it('does not read an unreported result as a zero', function () use ($now): void {
        [$health] = verdict(['results' => null, 'previousResults' => null], $now);

        expect($health)->not->toBe(CampaignHealth::Critical);
    });

    /* A reach campaign has no results to be missing. */
    it('does not apply to an objective with no counted result', function () use ($now): void {
        [$health] = verdict([
            'objective' => CampaignObjective::Reach,
            'results' => 0.0,
            'previousResults' => 0.0,
        ], $now);

        expect($health)->toBe(CampaignHealth::Healthy);
    });
});

describe('cost and delivery', function () use ($now): void {
    it('raises a cost rise of half again to needs attention', function () use ($now): void {
        [$health, $reason] = verdict(['results' => 60.0, 'previousResults' => 100.0], $now);

        expect($health)->toBe(CampaignHealth::NeedsAttention)
            ->and($reason)->toContain('67%');
    });

    it('keeps a smaller rise at watch', function () use ($now): void {
        [$health] = verdict(['results' => 80.0, 'previousResults' => 100.0], $now);

        expect($health)->toBe(CampaignHealth::Watch);
    });

    it('leaves a cost that improved alone', function () use ($now): void {
        [$health] = verdict(['results' => 140.0, 'previousResults' => 100.0], $now);

        expect($health)->toBe(CampaignHealth::Healthy);
    });

    it('notices delivery falling away even while cost per result holds', function () use ($now): void {
        [$health, $reason] = verdict([
            'spendMinor' => 250_000,
            'results' => 50.0,
            'previousSpendMinor' => 500_000,
            'previousResults' => 100.0,
        ], $now);

        expect($health)->toBe(CampaignHealth::Watch)
            ->and($reason)->toContain('50% less');
    });

    it('says nothing about a campaign with no previous period to compare', function () use ($now): void {
        [$health] = verdict(['previousSpendMinor' => null, 'previousResults' => null], $now);

        expect($health)->toBe(CampaignHealth::Healthy);
    });
});

describe('what a figure is allowed to mean', function (): void {
    /*
     * Rule 33, as arithmetic. A lead campaign carrying a revenue figure from a mis-mapped conversion must
     * not be handed a return the campaign cannot support.
     */
    it('refuses ROAS for an objective that does not measure revenue', function (): void {
        expect(vitals(['objective' => CampaignObjective::Leads])->roas())->toBeNull()
            ->and(vitals(['objective' => CampaignObjective::Sales])->roas())->toBe(4.0);
    });

    it('refuses a cost per result where the figure would be invented', function (): void {
        expect(vitals(['objective' => CampaignObjective::Reach])->costPerResult())->toBeNull()
            ->and(vitals(['objective' => CampaignObjective::Leads])->costPerResult())->toBe(5000.0);
    });

    it('refuses a cost per result with nothing to divide by', function (): void {
        expect(vitals(['results' => 0.0])->costPerResult())->toBeNull()
            ->and(vitals(['results' => null])->costPerResult())->toBeNull();
    });
});

describe('ordering', function (): void {
    it('sorts worst first, with inactive and unknown below healthy', function (): void {
        $states = CampaignHealth::cases();
        usort($states, static fn (CampaignHealth $a, CampaignHealth $b): int => $a->rank() <=> $b->rank());

        expect(array_map(static fn (CampaignHealth $s): string => $s->value, $states))->toBe([
            'critical', 'needs_attention', 'watch', 'insufficient_data', 'healthy', 'inactive',
        ]);
    });
});
