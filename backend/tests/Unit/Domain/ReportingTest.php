<?php

declare(strict_types=1);

use App\Domain\Analytics\Metric;
use App\Domain\Reporting\Comparability;
use App\Domain\Reporting\ComparisonMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ReportWindow;
use App\Domain\Reporting\SectionKind;
use App\Domain\Reporting\ShareState;
use App\Domain\Shared\ValueObject\DateRange;

/*
|--------------------------------------------------------------------------
| Reporting
|--------------------------------------------------------------------------
|
| A report is the most dangerous artefact this product makes: it gets e-mailed, quoted in a meeting, and read months
| later by somebody who was not in the conversation. So the rules tested here are the ones that stop a figure being
| quoted out of the context that made it true.
|
| "Spend is up 40%" is the single most quoted sentence an agency produces, and it is worthless when the two periods
| are different lengths or one of them is still running. Both are computable, so `Comparability` computes them — and
| deliberately says nothing about seasonality, which is not computable and which an honest "comparable" must not be
| taken to endorse.
|
*/

describe('comparability', function (): void {
    it('refuses a comparison between periods of different lengths', function (): void {
        $thirty = new DateRange(new DateTimeImmutable('2026-09-08'), new DateTimeImmutable('2026-10-07'));
        $august = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));

        $verdict = Comparability::of($thirty, $august, false);

        /*
         * Thirty days against a calendar month. A percentage between them measures the calendar as much as the
         * campaigns, and nothing on the page would have said so.
         */
        expect($verdict->isComparable)->toBeFalse();
        expect($verdict->code)->toBe('unequal_length');
        expect($verdict->reason)->toContain('different lengths');
    });

    it('refuses a comparison when the period is still running', function (): void {
        $current = new DateRange(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-07'));
        $before = $current->previousPeriod();

        $verdict = Comparability::of($current, $before, true);

        // Equal in length and still wrong: the current side is filling.
        expect($verdict->isComparable)->toBeFalse();
        expect($verdict->code)->toBe('period_incomplete');
    });

    it('refuses overlapping periods', function (): void {
        $period = new DateRange(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-10'));
        $overlapping = new DateRange(new DateTimeImmutable('2026-09-28'), new DateTimeImmutable('2026-10-07'));

        $verdict = Comparability::of($period, $overlapping, false);

        expect($verdict->isComparable)->toBeFalse();
        expect($verdict->code)->toBe('overlapping');
    });

    it('accepts the equally long period immediately before', function (): void {
        $period = new DateRange(new DateTimeImmutable('2026-09-08'), new DateTimeImmutable('2026-10-07'));

        $verdict = Comparability::of($period, $period->previousPeriod(), false);

        expect($verdict->isComparable)->toBeTrue();
        expect($verdict->reason)->toBeNull();
    });

    it('distinguishes a comparison nobody asked for from one it refused', function (): void {
        $none = Comparability::none();

        /*
         * Not a failure — just nothing to say. An interface that treated the two the same would show a warning on
         * every report without a comparison.
         */
        expect($none->isComparable)->toBeFalse();
        expect($none->wasRequested())->toBeFalse();
        expect($none->reason)->toBeNull();
    });

    it('says what each comparison cannot account for, per mode', function (): void {
        /*
         * Per mode rather than once, because the two fail differently — and a single generic warning is one nobody
         * reads. Neither claims the comparison is meaningful: that is a judgement no code here can make.
         */
        expect(ComparisonMode::PreviousPeriod->caveat())->toContain('seasonality');
        expect(ComparisonMode::SamePeriodLastYear->caveat())->toContain('judgement');
        expect(ComparisonMode::None->caveat())->toBeNull();
    });
});

describe('the period rule', function (): void {
    $today = new DateTimeImmutable('2026-10-08');

    it('ends every relative window yesterday', function () use ($today): void {
        foreach ([ReportWindow::Last7Days, ReportWindow::Last30Days, ReportWindow::MonthToDate] as $window) {
            /*
             * Today is still accumulating. Including it makes every metric look worse than it is and makes a
             * day-over-day comparison compare a partial day with a whole one.
             */
            expect($window->resolve($today)->end->format('Y-m-d'))->toBe('2026-10-07');
        }
    });

    it('resolves last month as the whole calendar month', function () use ($today): void {
        $range = ReportWindow::LastMonth->resolve($today);

        expect($range->start->format('Y-m-d'))->toBe('2026-09-01');
        expect($range->end->format('Y-m-d'))->toBe('2026-09-30');
    });

    it('marks month-to-date as incomplete and nothing else', function (): void {
        expect(ReportWindow::MonthToDate->isIncomplete())->toBeTrue();

        foreach ([ReportWindow::Last30Days, ReportWindow::LastMonth, ReportWindow::Custom] as $window) {
            expect($window->isIncomplete())->toBeFalse();
        }
    });

    it('needs dates only for a specific period', function (): void {
        expect(ReportWindow::Custom->needsDates())->toBeTrue();
        expect(ReportWindow::Last30Days->needsDates())->toBeFalse();
    });
});

describe('section shapes', function (): void {
    it('refuses a non-additive metric in a ranking or a table', function (): void {
        foreach ([SectionKind::Breakdown, SectionKind::Table] as $kind) {
            /*
             * Reach counts deduplicated people, so a reader adding the rows double-counts anybody who appears in two
             * of them. The read model already refuses the total; this refuses the column, which is where the
             * invitation to add was.
             */
            expect($kind->accepts(Metric::Reach))->toBeFalse();
            expect($kind->accepts(Metric::Frequency))->toBeFalse();
            expect($kind->accepts(Metric::Spend))->toBeTrue();
        }
    });

    it('allows reach in a scorecard and a trend, where there is nothing to add it to', function (): void {
        expect(SectionKind::Scorecard->accepts(Metric::Reach))->toBeTrue();
        expect(SectionKind::Trend->accepts(Metric::Reach))->toBeTrue();
    });

    it('supports a comparison on a scorecard alone', function (): void {
        /*
         * A trend already shows change over time, and a second period's line would have to pretend day 3 of one is
         * day 3 of the other. A compared ranking is a table of twelve numbers nobody reads.
         */
        expect(SectionKind::Scorecard->supportsComparison())->toBeTrue();

        foreach ([SectionKind::Trend, SectionKind::Breakdown, SectionKind::Table] as $kind) {
            expect($kind->supportsComparison())->toBeFalse();
        }
    });

    it('caps a trend tighter than a table', function (): void {
        // Four lines is the measured limit where series cross; a table uses position rather than colour.
        expect(SectionKind::Trend->metricCap())->toBe(4);
        expect(SectionKind::Table->metricCap())->toBeGreaterThan(SectionKind::Trend->metricCap());
    });

    it('needs a dimension only where it groups', function (): void {
        expect(SectionKind::Breakdown->needsDimension())->toBeTrue();
        expect(SectionKind::Table->needsDimension())->toBeTrue();
        expect(SectionKind::Scorecard->needsDimension())->toBeFalse();
    });
});

describe('share state', function (): void {
    $now = new DateTimeImmutable('2026-10-08 12:00:00');

    it('derives expiry rather than storing it', function () use ($now): void {
        /*
         * A stored `expired` would need something to write it, and whatever that was would be the thing that failed
         * to run — leaving a link expired in principle and working in practice.
         */
        $lapsed = ShareState::of(false, new DateTimeImmutable('2026-10-07 12:00:00'), $now);

        expect($lapsed)->toBe(ShareState::Expired);
        expect($lapsed->allowsAccess())->toBeFalse();
    });

    it('treats revocation as immediate, whatever the expiry says', function () use ($now): void {
        // Somebody revokes a link because it went to the wrong address. "Next month" is not an answer to that.
        $revoked = ShareState::of(true, new DateTimeImmutable('2027-01-01 00:00:00'), $now);

        expect($revoked)->toBe(ShareState::Revoked);
    });

    it('tells a visitor which it was, and never "not found"', function (): void {
        /*
         * A 404 on a link a client was sent reads as a broken site; they ask for it again instead of learning it
         * lapsed. The message says which it was and nothing about the report behind it.
         */
        expect(ShareState::Expired->visitorMessage())->toContain('expired');
        expect(ShareState::Revoked->visitorMessage())->toContain('turned off');
        expect(ShareState::Active->visitorMessage())->toBeNull();
    });
});

describe('audience', function (): void {
    it('allows internal notes on an internal report only', function (): void {
        expect(ReportAudience::Internal->allowsInternalNotes())->toBeTrue();
        expect(ReportAudience::ClientSafe->allowsInternalNotes())->toBeFalse();
    });
});
