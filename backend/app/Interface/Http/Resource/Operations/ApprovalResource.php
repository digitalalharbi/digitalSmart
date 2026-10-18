<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Operations;

use App\Application\Operations\DTO\ApprovalQueueView;
use App\Application\Operations\DTO\ApprovalView;
use App\Domain\Operations\ApprovalAge;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalLoad;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use DateTimeImmutable;

final readonly class ApprovalResource
{
    /**
     * The module's vocabulary.
     *
     * `is_enacted_here` and `enactment_blocked_because` are published per kind so the interface can say, on
     * the request itself, that approving it will not make the change. Leaving the interface to assume would
     * eventually produce a screen that implies the product pressed the button.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'kinds' => array_map(static fn (ApprovalKind $kind): array => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'touches_live_spend' => $kind->touchesLiveSpend(),
                'is_enacted_here' => $kind->isEnactedByThisProduct(),
                'enactment_blocked_because' => $kind->enactmentBlockedBecause(),
                /* The extra permission a decider needs, so a button can be disabled rather than refused. */
                'decided_with' => $kind->decidedWith()?->value,
                'usual_subject' => $kind->usualSubject()?->value,
            ], ApprovalKind::cases()),
            'states' => array_map(static fn (ApprovalState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'position' => $state->position(),
                'is_pending' => $state->isPending(),
                'needs_reason' => $state->needsReason(),
                'is_amendable' => $state->isAmendable(),
            ], ApprovalState::cases()),
            'enactments' => array_map(static fn (EnactmentState $enactment): array => [
                'enactment' => $enactment->value,
                'label' => $enactment->label(),
                'is_outstanding' => $enactment->isOutstanding(),
                'is_settled' => $enactment->isSettled(),
            ], EnactmentState::cases()),
            'ages' => array_map(static fn (ApprovalAge $age): array => [
                'age' => $age->value,
                'label' => $age->label(),
                'rank' => $age->rank(),
                'needs_attention' => $age->needsAttention(),
            ], ApprovalAge::ordered()),
            'patience_days' => ApprovalLoad::PATIENCE_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    public static function queue(ApprovalQueueView $queue): array
    {
        $load = $queue->load;

        return [
            /*
             * Two lists, not one. Requests waiting for a decision and approved changes waiting for somebody to
             * make them are different backlogs with different owners, and merging them hides the second.
             */
            'pending' => array_map(
                static fn (ApprovalView $view): array => self::row($view, $queue->now),
                $queue->pending,
            ),
            'outstanding' => array_map(
                static fn (ApprovalView $view): array => self::row($view, $queue->now),
                $queue->outstanding,
            ),
            'settled' => array_map(
                static fn (ApprovalView $view): array => self::row($view, $queue->now),
                $queue->settled,
            ),
            'load' => [
                'pending' => $load->pending,
                'pending_touching_spend' => $load->pendingTouchingSpend,
                'approved' => $load->approved,
                'declined' => $load->declined,
                'withdrawn' => $load->withdrawn,
                /* The figure this module exists for: approved changes nobody has carried out. */
                'outstanding' => $load->outstanding,
                'confirmed' => $load->confirmed,
                'abandoned' => $load->abandoned,
                /* Null, not zero, with nothing waiting: "no backlog" and "a backlog of today" differ. */
                'oldest_outstanding_days' => $load->oldestOutstandingDays,
                'oldest_pending_days' => $load->oldestPendingDays,
                'pending_by_age' => $load->pendingByAge,
                'outstanding_by_age' => $load->outstandingByAge,
                'pending_by_kind' => $load->byKind,
                'carried_out_rate' => $load->carriedOutRate(),
                'approval_rate' => $load->approvalRate(),
                'needs_attention' => $load->needsAttention(),
                'patience_days' => $load::PATIENCE_DAYS,
            ],
            'as_of' => $queue->now->format(DateTimeImmutable::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(ApprovalView $view, DateTimeImmutable $now): array
    {
        return self::row($view, $now) + [
            'rationale' => $view->rationale,
            'proposal' => $view->proposal,
            'decision_reason' => $view->decisionReason,
            'withdrawn_reason' => $view->withdrawnReason,
            'enactment_note' => $view->enactmentNote,
            'enacted_by_name' => $view->enactedByName,
            'enacted_at' => $view->enactedAt?->format(DateTimeImmutable::ATOM),
            'supersedes_id' => $view->supersedesId,
            'decision_days' => $view->decisionDays(),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(ApprovalView $view, DateTimeImmutable $now): array
    {
        $age = $view->age($now);

        return [
            'id' => $view->id,
            'kind' => $view->kind->value,
            'kind_label' => $view->kind->label(),
            'title' => $view->title,
            /*
             * On every row, not only the opened one: a one-decision card has to show what is being proposed, and a
             * list that made a second request per card to find out would not be one decision.
             */
            'rationale' => $view->rationale,
            'proposal' => $view->proposal,
            'state' => $view->state->value,
            'state_label' => $view->state->label(),
            'enactment' => $view->enactment->value,
            'enactment_label' => $view->enactment->label(),
            'touches_live_spend' => $view->kind->touchesLiveSpend(),
            /*
             * On every row. A reader scanning the queue has to be able to see that approving this does not make
             * the change, without opening it.
             */
            'is_enacted_here' => $view->isEnactedHere(),
            'enactment_blocked_because' => $view->kind->enactmentBlockedBecause(),
            /*
             * And whether the *second* act is available: approving never enacts, but carrying out can now be
             * done here for a request that names a platform campaign on a provider whose write this product has
             * read in its own documentation. The sentence is published rather than the control hidden — a missing
             * button produces a support question, and the sentence produces somebody going to the platform.
             */
            'can_be_carried_out_here' => $view->canBeCarriedOutHere(),
            'carry_out_blocked_because' => $view->carryOutBlockedBecause(),
            /*
             * Whether the proposal still matches what was approved. False means somebody amended it, and the
             * write path refuses — carrying out an amended proposal would carry out a change nobody signed off.
             */
            'proposal_matches_decision' => $view->proposalMatchesDecision(),
            /*
             * The hash a decision must send back. Published on every row because a decider's client has to
             * return the one it displayed — see `ProposalFingerprint`.
             */
            'fingerprint' => $view->fingerprint,
            'fingerprint_short' => substr($view->fingerprint, 0, 12),
            'client_id' => $view->clientId,
            'client_name' => $view->clientName,
            'project_id' => $view->projectId,
            'project_name' => $view->projectName,
            'requested_by_name' => $view->requestedByName,
            'created_at' => $view->createdAt->format(DateTimeImmutable::ATOM),
            'decided_by_name' => $view->decidedByName,
            'decided_at' => $view->decidedAt?->format(DateTimeImmutable::ATOM),
            /* Null when nothing is waiting, which is different from waiting no time at all. */
            'age' => $age?->value,
            'age_label' => $age?->label(),
            'waiting_days' => $view->waitingDays($now),
            'subject' => $view->subject === null ? null : [
                'type' => $view->subject->type->value,
                'type_label' => $view->subject->type->label(),
                'id' => $view->subject->id,
                'label' => $view->subject->label,
                'exists' => $view->subject->exists,
                'path' => $view->subject->path(),
            ],
        ];
    }
}
