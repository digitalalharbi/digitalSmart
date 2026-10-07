<?php

declare(strict_types=1);

use App\Domain\Operations\DueHorizon;
use App\Domain\Operations\Exception\InvalidTaskTransition;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use App\Domain\Operations\TaskWorkload;

/*
|--------------------------------------------------------------------------
| Operational work
|--------------------------------------------------------------------------
|
| Three rules carry this module, and each has an obvious wrong version that a board somewhere ships:
|
|  - **Undated work is not "later".** A task with no due date is its own bucket, counted on its own, because
|    a board that folds the two together reports a tidy pipeline while a third of its work could not be late.
|  - **Only open work is overdue.** Finished work that was late is history; counting it leaves a figure that
|    can never be cleared.
|  - **Blocked does not lead to done.** The shortcut loses both the fact that somebody did the work and any
|    way to measure what the block cost.
|
*/

describe('due horizons', function (): void {
    $today = new DateTimeImmutable('2026-10-07 14:30:00');

    it('puts work with no date in its own bucket', function () use ($today): void {
        /*
         * The rule this enum exists for. "Later" would make the due profile add up and the board lie: the
         * figure a reader needs is how much work nobody has dated, and absorbing it is how that figure
         * disappears.
         */
        expect(DueHorizon::of(null, $today, true))->toBe(DueHorizon::Undated);
        expect(DueHorizon::Undated->label())->toBe('No due date');
    });

    it('never calls finished work overdue', function () use ($today): void {
        $lastWeek = new DateTimeImmutable('2026-09-30');

        expect(DueHorizon::of($lastWeek, $today, true))->toBe(DueHorizon::Overdue);
        // Closed and late is history. An overdue count including it could never reach zero.
        expect(DueHorizon::of($lastWeek, $today, false))->not->toBe(DueHorizon::Overdue);
    });

    it('treats a task due today as due today, not as late by lunchtime', function () use ($today): void {
        /*
         * The reason `due_on` is a date. Comparing a date against a timestamp makes everything due today
         * overdue from one minute past midnight, and then nobody believes the overdue figure.
         */
        expect(DueHorizon::of(new DateTimeImmutable('2026-10-07'), $today, true))->toBe(DueHorizon::Today);
    });

    it('separates the coming week from later', function () use ($today): void {
        expect(DueHorizon::of(new DateTimeImmutable('2026-10-12'), $today, true))->toBe(DueHorizon::ThisWeek);
        expect(DueHorizon::of(new DateTimeImmutable('2026-10-14'), $today, true))->toBe(DueHorizon::ThisWeek);
        expect(DueHorizon::of(new DateTimeImmutable('2026-10-15'), $today, true))->toBe(DueHorizon::Later);
    });

    it('orders the buckets soonest first with undated at the end', function (): void {
        expect(array_map(
            static fn (DueHorizon $horizon): string => $horizon->value,
            DueHorizon::ordered(),
        ))->toBe(['overdue', 'today', 'this_week', 'later', 'undated']);
    });
});

describe('the board', function (): void {
    it('refuses the move from blocked to done, and says why', function (): void {
        expect(TaskState::Blocked->canMoveTo(TaskState::Done))->toBeFalse();

        try {
            TaskState::Blocked->assertCanMoveTo(TaskState::Done);
            /* Reaching here would mean the rule is gone. */
            expect(false)->toBeTrue();
        } catch (InvalidTaskTransition $refusal) {
            // Named rather than generic, because this is the move people actually try.
            expect($refusal->getMessage())->toContain('Put it back in progress first');
            expect($refusal->allowed)->toBe(['in_progress', 'cancelled']);
        }
    });

    it('requires a reason for blocked and cancelled, and for nothing else', function (): void {
        expect(TaskState::Blocked->needsReason())->toBeTrue();
        expect(TaskState::Cancelled->needsReason())->toBeTrue();

        foreach ([TaskState::Todo, TaskState::InProgress, TaskState::InReview, TaskState::Done] as $state) {
            expect($state->needsReason())->toBeFalse();
        }
    });

    it('counts only done as completed', function (): void {
        expect(TaskState::Done->isCompleted())->toBeTrue();
        // Cancelled work was not completed. Counting it as such flatters every report drawn from it.
        expect(TaskState::Cancelled->isCompleted())->toBeFalse();
        expect(TaskState::Cancelled->isOpen())->toBeFalse();
    });

    it('lets finished work be reopened', function (): void {
        // A real board does this. Making it impossible only means somebody edits the row by hand.
        expect(TaskState::Done->allowedNext())->toBe([TaskState::InProgress]);
    });

    it('orders the columns and names the open ones', function (): void {
        expect(array_map(
            static fn (TaskState $state): string => $state->value,
            TaskState::board(),
        ))->toBe(['todo', 'in_progress', 'blocked', 'in_review', 'done', 'cancelled']);

        expect(array_map(
            static fn (TaskState $state): string => $state->value,
            TaskState::open(),
        ))->toBe(['todo', 'in_progress', 'blocked', 'in_review']);
    });
});

describe('priority', function (): void {
    it('is an ordered scale, which is why it carries a rank', function (): void {
        expect(array_map(
            static fn (TaskPriority $priority): string => $priority->value,
            TaskPriority::ascending(),
        ))->toBe(['low', 'normal', 'high', 'urgent']);

        expect(TaskPriority::Urgent->rank())->toBeGreaterThan(TaskPriority::High->rank());
    });

    it('elevates only high and urgent', function (): void {
        expect(TaskPriority::Urgent->isElevated())->toBeTrue();
        expect(TaskPriority::High->isElevated())->toBeTrue();
        expect(TaskPriority::Normal->isElevated())->toBeFalse();
    });
});

describe('the workload', function (): void {
    $workload = static fn (int $open, int $undated, int $done, int $cancelled): TaskWorkload => new TaskWorkload(
        total: $open + $done + $cancelled,
        open: $open,
        done: $done,
        cancelled: $cancelled,
        overdue: 0,
        undated: $undated,
        unassigned: 0,
        elevated: 0,
        waiting: 0,
        byState: [],
        byHorizon: [],
    );

    it('has no completion rate until something has settled', function () use ($workload): void {
        // Zero percent and "nothing to measure" look identical on a card and mean opposite things.
        expect($workload(5, 0, 0, 0)->completionRate())->toBeNull();
        expect($workload(5, 0, 3, 1)->completionRate())->toBe(75.0);
    });

    it('measures completion against settled work, not against the whole board', function () use ($workload): void {
        /*
         * A team that has just taken on fifty new tasks has not got worse at finishing things. Dividing by
         * everything would say it had.
         */
        expect($workload(50, 0, 3, 1)->completionRate())->toBe(75.0);
    });

    it('refuses to call the overdue figure a measure of lateness when most work is undated', function () use ($workload): void {
        $mostlyDated = $workload(10, 1, 0, 0);
        $mostlyUndated = $workload(10, 5, 0, 0);

        expect($mostlyDated->datedShare())->toBe(90.0);
        expect($mostlyDated->isLatenessReportable())->toBeTrue();

        /*
         * Half the board has no date, so "two overdue" describes the half that could be late. The interface
         * says so beside the figure rather than leaving a reader to assume the rest is on time.
         */
        expect($mostlyUndated->datedShare())->toBe(50.0);
        expect($mostlyUndated->isLatenessReportable())->toBeFalse();
    });

    it('has no dated share with nothing open', function () use ($workload): void {
        expect($workload(0, 0, 4, 0)->datedShare())->toBeNull();
        expect($workload(0, 0, 4, 0)->isLatenessReportable())->toBeFalse();
    });
});
