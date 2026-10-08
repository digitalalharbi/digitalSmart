<?php

declare(strict_types=1);

use App\Domain\Operations\ApprovalAge;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalLoad;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use App\Domain\Operations\ProposalFingerprint;

/*
|--------------------------------------------------------------------------
| Approvals
|--------------------------------------------------------------------------
|
| Two rules carry this module.
|
| **Approval is not execution.** This product does not write to the ad platforms, so approving a pause pauses
| nothing. Every kind says so, an approved request waits on a person, and the queue counts how much approved
| work nobody has carried out — the figure an agency never has, and the one that ends with a client being told
| their campaign was stopped while it kept spending.
|
| **A decision names the proposal it read.** The fingerprint is over a canonical form, so a key reordering does
| not trip it and an actual amendment does.
|
*/

describe('what approval means', function (): void {
    it('says approving a kind never makes the change, and why', function (): void {
        foreach (ApprovalKind::cases() as $kind) {
            /*
             * The claim the whole module rests on, and the kind is the only level at which it is unconditional:
             * approving something never makes it happen. Since Phase 25 this product *can* carry three
             * operations out, but whether a given request can be is a property of the request — it has to name
             * the campaign, on a provider whose write was read in its own documentation. Asserted rather than
             * assumed, so a change to either claim has to be made deliberately.
             */
            expect($kind->isEnactedByThisProduct())->toBeFalse();
            expect($kind->enactmentBlockedBecause())->not->toBeNull();
            expect($kind->enactmentBlockedBecause())->toContain('marks it done here');
        }
    });

    it('counts targeting as touching live spend', function (): void {
        // It does not change how much is spent, but it changes who it is spent on. Same conversation.
        expect(ApprovalKind::TargetingChange->touchesLiveSpend())->toBeTrue();
        expect(ApprovalKind::SpendChange->touchesLiveSpend())->toBeTrue();
        expect(ApprovalKind::TrackingChange->touchesLiveSpend())->toBeFalse();
    });

    it('needs a budget approver for a spend change and nobody extra for a pause', function (): void {
        expect(ApprovalKind::SpendChange->decidedWith()?->value)->toBe('budgets.approve');
        /*
         * Stopping spend is the safe direction. Requiring a budget approver to halt a runaway campaign is how a
         * runaway campaign keeps running over a weekend.
         */
        expect(ApprovalKind::CampaignPause->decidedWith())->toBeNull();
    });
});

describe('the state machine', function (): void {
    it('refuses to re-decide something settled', function (): void {
        foreach ([ApprovalState::Approved, ApprovalState::Declined, ApprovalState::Withdrawn] as $settled) {
            try {
                $settled->assertCanMoveTo(ApprovalState::Approved);
                expect(false)->toBeTrue();
            } catch (InvalidApprovalTransition $refusal) {
                /*
                 * A trail in which one request was both declined and approved cannot say which decision the
                 * client was told about. A changed proposal is a new request naming the one it replaces.
                 */
                expect($refusal->reasonCode)->toBe('already_settled');
                expect($refusal->getMessage())->toContain('raise a new one');
            }
        }
    });

    it('asks for a reason to decline or withdraw, and not to approve', function (): void {
        expect(ApprovalState::Declined->needsReason())->toBeTrue();
        expect(ApprovalState::Withdrawn->needsReason())->toBeTrue();
        /*
         * "Yes" needs no explanation, and demanding one gets "ok" typed into the box — which is worse than an
         * empty field, because it looks like a record.
         */
        expect(ApprovalState::Approved->needsReason())->toBeFalse();
    });

    it('allows an amendment only while pending', function (): void {
        expect(ApprovalState::Pending->isAmendable())->toBeTrue();
        expect(ApprovalState::Approved->isAmendable())->toBeFalse();
    });
});

describe('the fingerprint', function (): void {
    it('ignores the order keys were serialised in', function (): void {
        $one = ProposalFingerprint::of(['to' => 800, 'from' => 500, 'currency' => 'SAR']);
        $two = ProposalFingerprint::of(['currency' => 'SAR', 'from' => 500, 'to' => 800]);

        // Otherwise the rule would fire on a key reordering and teach people to ignore it.
        expect($one->value)->toBe($two->value);
    });

    it('moves when the content changes', function (): void {
        $before = ProposalFingerprint::of(['from' => 500, 'to' => 800]);
        $after = ProposalFingerprint::of(['from' => 500, 'to' => 900]);

        expect($before->value)->not->toBe($after->value);
        expect($before->matches($after->value))->toBeFalse();
    });

    it('keeps the order of a list, because the order of a list is part of what it says', function (): void {
        $one = ProposalFingerprint::of(['placements' => ['feed', 'stories']]);
        $two = ProposalFingerprint::of(['placements' => ['stories', 'feed']]);

        expect($one->value)->not->toBe($two->value);
    });

    it('never matches a missing fingerprint', function (): void {
        $print = ProposalFingerprint::of(['a' => 1]);

        /*
         * A caller that omits it has not said what it was looking at, and treating silence as agreement is the
         * whole failure this class prevents.
         */
        expect($print->matches(null))->toBeFalse();
    });
});

describe('ages', function (): void {
    $now = new DateTimeImmutable('2026-10-07 09:00:00');

    it('buckets a wait into the four answers somebody acts on', function () use ($now): void {
        expect(ApprovalAge::of(new DateTimeImmutable('2026-10-07'), $now))->toBe(ApprovalAge::Today);
        expect(ApprovalAge::of(new DateTimeImmutable('2026-10-05'), $now))->toBe(ApprovalAge::Days);
        expect(ApprovalAge::of(new DateTimeImmutable('2026-10-01'), $now))->toBe(ApprovalAge::Week);
        expect(ApprovalAge::of(new DateTimeImmutable('2026-09-20'), $now))->toBe(ApprovalAge::Stale);
    });

    it('calls a wait of over half a week attention-worthy', function (): void {
        expect(ApprovalAge::Stale->needsAttention())->toBeTrue();
        expect(ApprovalAge::Week->needsAttention())->toBeTrue();
        expect(ApprovalAge::Today->needsAttention())->toBeFalse();
    });
});

describe('the queue figures', function (): void {
    $load = static fn (
        int $pending,
        int $approved,
        int $declined,
        int $outstanding,
        int $confirmed,
        int $abandoned,
        ?int $oldestPending = null,
    ): ApprovalLoad => new ApprovalLoad(
        pending: $pending,
        pendingTouchingSpend: 0,
        approved: $approved,
        declined: $declined,
        withdrawn: 0,
        outstanding: $outstanding,
        confirmed: $confirmed,
        abandoned: $abandoned,
        oldestOutstandingDays: $outstanding === 0 ? null : 2,
        oldestPendingDays: $oldestPending,
        pendingByAge: [],
        outstandingByAge: [],
        byKind: [],
    );

    it('has no carried-out rate until something has settled', function () use ($load): void {
        // Work still waiting is not yet a failure to act, so it is not in the denominator either.
        expect($load(0, 3, 0, 3, 0, 0)->carriedOutRate())->toBeNull();
        expect($load(0, 4, 0, 1, 2, 1)->carriedOutRate())->toBe(66.7);
    });

    it('excludes withdrawals from the approval rate', function (): void {
        $load = new ApprovalLoad(
            pending: 0, pendingTouchingSpend: 0, approved: 3, declined: 1, withdrawn: 6,
            outstanding: 0, confirmed: 3, abandoned: 0,
            oldestOutstandingDays: null, oldestPendingDays: null,
            pendingByAge: [], outstandingByAge: [], byKind: [],
        );

        // A request the asker took back was never decided, so it says nothing about how decisions go.
        expect($load->approvalRate())->toBe(75.0);
    });

    it('has no approval rate before anything is decided', function () use ($load): void {
        expect($load(4, 0, 0, 0, 0, 0)->approvalRate())->toBeNull();
    });

    it('needs attention whenever approved work is waiting at all', function () use ($load): void {
        /*
         * Not after a grace period. One approved change nobody has made is already somebody believing
         * something that is not true.
         */
        expect($load(0, 1, 0, 1, 0, 0)->needsAttention())->toBeTrue();
        expect($load(1, 0, 0, 0, 0, 0, 1)->needsAttention())->toBeFalse();
        expect($load(1, 0, 0, 0, 0, 0, 5)->needsAttention())->toBeTrue();
    });
});

describe('enactment', function (): void {
    it('treats only awaiting-a-hand as outstanding', function (): void {
        expect(EnactmentState::AwaitingHand->isOutstanding())->toBeTrue();
        expect(EnactmentState::Confirmed->isOutstanding())->toBeFalse();
        /* Not required is not outstanding: there is nothing to do, which is different from nothing done. */
        expect(EnactmentState::NotRequired->isOutstanding())->toBeFalse();
    });
});
