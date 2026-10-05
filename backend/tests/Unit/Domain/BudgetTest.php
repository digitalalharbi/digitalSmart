<?php

declare(strict_types=1);

use App\Domain\Budget\AllocationLevel;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\Pacing;
use App\Domain\Budget\PacingStatus;

/*
|--------------------------------------------------------------------------
| Budget domain
|--------------------------------------------------------------------------
|
| Pacing is the arithmetic a client's money is judged by, so every figure here is
| derived from four facts and nothing else: the approved amount, the period, what was
| actually spent, and today. The tests below pin down the two refusals as firmly as the
| sums — a projection needs elapsed days, and a budget nobody set is not "on track".
|
*/

/** A thirty-day period, to keep the arithmetic readable. */
function pacing(int $budget, int $spent, string $today, string $start = '2026-10-01', string $end = '2026-10-30'): Pacing
{
    return Pacing::compute(
        budgetMinor: $budget,
        spentMinor: $spent,
        periodStart: new DateTimeImmutable($start),
        periodEnd: new DateTimeImmutable($end),
        today: new DateTimeImmutable($today),
        currency: 'SAR',
    );
}

describe('pacing arithmetic', function (): void {
    it('counts both ends of the period', function (): void {
        // A one-day budget covers one day, not zero.
        expect(pacing(100_000, 0, '2026-10-01', '2026-10-01', '2026-10-01')->totalDays)->toBe(1)
            ->and(pacing(100_000, 0, '2026-10-01')->totalDays)->toBe(30);
    });

    it('expects an even share of the budget by today', function (): void {
        // Day ten of thirty: a third of the budget.
        $pacing = pacing(300_000, 100_000, '2026-10-10');

        expect($pacing->elapsedDays)->toBe(10)
            ->and($pacing->remainingDays)->toBe(20)
            ->and($pacing->expectedMinor)->toBe(100_000)
            ->and($pacing->varianceMinor)->toBe(0)
            ->and($pacing->status)->toBe(PacingStatus::OnTrack);
    });

    it('projects today’s rate to the end of the period', function (): void {
        // 150,000 over ten days is 15,000 a day, which lands at 450,000 over thirty.
        $pacing = pacing(300_000, 150_000, '2026-10-10');

        expect($pacing->projectedMinor)->toBe(450_000)
            ->and($pacing->risksOverspend())->toBeTrue()
            ->and($pacing->status)->toBe(PacingStatus::Overspending);
    });

    it('refuses to project before the period starts', function (): void {
        $pacing = pacing(300_000, 0, '2026-09-20');

        /*
         * Null, not zero. Zero would read as "forecast: nothing will be spent", which is
         * a claim about the future made from no evidence at all.
         */
        expect($pacing->projectedMinor)->toBeNull()
            ->and($pacing->status)->toBe(PacingStatus::NotStarted)
            ->and($pacing->elapsedDays)->toBe(0);
    });

    it('treats a budget nobody has set as unknown, not as on track', function (): void {
        $pacing = pacing(0, 0, '2026-10-10');

        /*
         * A budget of zero is permanently exhausted by the arithmetic, which would raise
         * an overspend alert about a figure nobody has agreed yet. Saying "unknown" is
         * the only answer that is not a reassurance nobody checked.
         */
        expect($pacing->status)->toBe(PacingStatus::Unknown)
            ->and($pacing->variancePercent)->toBeNull()
            ->and($pacing->projectedMinor)->toBeNull()
            ->and($pacing->utilisation())->toBeNull();
    });

    it('refuses a period that ends before it starts', function (): void {
        $pacing = pacing(300_000, 0, '2026-10-10', '2026-10-30', '2026-10-01');

        expect($pacing->totalDays)->toBe(0)
            ->and($pacing->status)->toBe(PacingStatus::Unknown);
    });

    it('allows ten per cent either way before it says anything', function (): void {
        // Day fifteen of thirty expects 150,000.
        expect(pacing(300_000, 160_000, '2026-10-15')->status)->toBe(PacingStatus::OnTrack)
            ->and(pacing(300_000, 140_000, '2026-10-15')->status)->toBe(PacingStatus::OnTrack)
            // Tighter and every campaign is permanently amber on ordinary variation.
            ->and(pacing(300_000, 170_000, '2026-10-15')->status)->toBe(PacingStatus::Overspending)
            ->and(pacing(300_000, 130_000, '2026-10-15')->status)->toBe(PacingStatus::Underspending);
    });

    it('calls a spent budget exhausted even in the middle of the period', function (): void {
        $pacing = pacing(300_000, 300_000, '2026-10-05');

        /*
         * Checked before the end date, because a budget that ran out on day five has a
         * problem worth naming for the rest of the month. "Ended" would bury it.
         */
        expect($pacing->status)->toBe(PacingStatus::Exhausted)
            ->and($pacing->remainingMinor)->toBe(0)
            ->and($pacing->status->isOverspend())->toBeTrue();
    });

    it('reports an overspent budget as negative remaining rather than zero', function (): void {
        $pacing = pacing(300_000, 340_000, '2026-10-28');

        // The overspend is the number somebody has to answer for; clamping it to zero
        // would hide exactly that.
        expect($pacing->remainingMinor)->toBe(-40_000)
            ->and($pacing->status)->toBe(PacingStatus::Exhausted);
    });

    it('says ended once the period is over and the budget was not spent', function (): void {
        $pacing = pacing(300_000, 250_000, '2026-11-02');

        expect($pacing->status)->toBe(PacingStatus::Ended)
            ->and($pacing->elapsedDays)->toBe(30)
            ->and($pacing->remainingDays)->toBe(0)
            // No day left to spend it on, so no allowance is offered.
            ->and($pacing->dailyAllowanceMinor)->toBeNull();
    });

    it('spreads what is left over the days that are left', function (): void {
        // 200,000 left over the final twenty days.
        $pacing = pacing(300_000, 100_000, '2026-10-10');

        expect($pacing->dailyAllowanceMinor)->toBe(10_000);
    });

    it('offers no allowance when there is nothing left to spend', function (): void {
        $pacing = pacing(300_000, 320_000, '2026-10-10');

        // Zero rather than a negative daily figure: "spend minus two hundred a day" is
        // not an instruction anyone can follow.
        expect($pacing->dailyAllowanceMinor)->toBe(0);
    });

    it('flags under-delivery only while there is still time to fix it', function (): void {
        $during = pacing(300_000, 60_000, '2026-10-15');
        $after = pacing(300_000, 120_000, '2026-11-05');

        expect($during->risksUnderDelivery())->toBeTrue()
            // Once the period has ended a short landing is not a risk, it is the result.
            ->and($after->risksUnderDelivery())->toBeFalse();
    });

    it('does not flag under-delivery for a landing within a tenth of the budget', function (): void {
        // Day ten, 92,000 spent → projected 276,000 of 300,000, which is 92 per cent.
        $pacing = pacing(300_000, 92_000, '2026-10-10');

        expect($pacing->projectedMinor)->toBe(276_000)
            ->and($pacing->risksUnderDelivery())->toBeFalse();
    });

    it('reports utilisation and elapsed time separately', function (): void {
        $pacing = pacing(300_000, 150_000, '2026-10-10');

        /*
         * Two different fractions, and the gap between them *is* the pacing story: half
         * the money gone in a third of the time.
         */
        expect($pacing->utilisation())->toBe(0.5)
            ->and($pacing->periodElapsed())->toBe(10 / 30);
    });

    it('keeps the currency it was given', function (): void {
        expect(pacing(300_000, 1, '2026-10-10')->currency)->toBe('SAR');
    });
});

describe('budget status', function (): void {
    it('fixes the amount once a budget is approved', function (): void {
        /*
         * The whole point of the record: changing the figure a client agreed to,
         * silently, is what a budget exists to prevent. Allocations stay editable, which
         * is how a plan adapts without the commitment moving.
         */
        expect(BudgetStatus::Draft->allowsAmountChange())->toBeTrue()
            ->and(BudgetStatus::Approved->allowsAmountChange())->toBeFalse()
            ->and(BudgetStatus::Approved->allowsAllocationChange())->toBeTrue()
            ->and(BudgetStatus::Archived->allowsAllocationChange())->toBeFalse();
    });

    it('paces only an approved budget', function (): void {
        // A draft is a proposal. Pacing against it would report a variance from a figure
        // nobody has agreed to.
        expect(BudgetStatus::Approved->isPaced())->toBeTrue()
            ->and(BudgetStatus::Draft->isPaced())->toBeFalse()
            ->and(BudgetStatus::Archived->isPaced())->toBeFalse();
    });
});

describe('allocation levels', function (): void {
    it('knows which level each one sits under', function (): void {
        expect(AllocationLevel::Platform->parent())->toBeNull()
            ->and(AllocationLevel::Campaign->parent())->toBe(AllocationLevel::Platform)
            ->and(AllocationLevel::AdGroup->parent())->toBe(AllocationLevel::Campaign);
    });

    it('requires a platform at every level', function (): void {
        // An allocation that does not say which platform it is for cannot be compared
        // with any spend, which makes it a number in a spreadsheet rather than a budget.
        foreach (AllocationLevel::cases() as $level) {
            expect($level->requiresProvider())->toBeTrue();
        }
    });
});
