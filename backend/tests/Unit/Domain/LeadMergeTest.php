<?php

declare(strict_types=1);

use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\Exception\LeadsCannotBeMerged;
use App\Domain\Leads\LeadMergeCandidate;
use App\Domain\Leads\LeadMergePlan;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;

/*
|--------------------------------------------------------------------------
| Merging two leads
|--------------------------------------------------------------------------
|
| The roadmap recorded this operation as needing "a decision about whose history survives". These tests are
| that decision, written down: which record continues, whose age it keeps, whose attribution it takes, and the
| four merges this product refuses outright.
|
*/

function mergeCandidate(
    string $id,
    string $receivedAt,
    LeadSourceKind $kind = LeadSourceKind::FormSubmission,
    LeadStage $stage = LeadStage::New,
    AttributionEvidence $evidence = AttributionEvidence::None,
    bool $isAlreadyMerged = false,
): LeadMergeCandidate {
    return new LeadMergeCandidate(
        id: $id,
        sourceKind: $kind,
        stage: $stage,
        evidence: $evidence,
        receivedAt: new DateTimeImmutable($receivedAt),
        isAlreadyMerged: $isAlreadyMerged,
    );
}

describe('which record survives', function (): void {
    it('keeps the earlier arrival, whichever order it is asked in', function (): void {
        $early = mergeCandidate('early', '2026-09-01 10:00:00');
        $late = mergeCandidate('late', '2026-09-20 10:00:00');

        expect(LeadMergePlan::of($early, $late)->survivor->id)->toBe('early')
            ->and(LeadMergePlan::of($late, $early)->survivor->id)->toBe('early');
    });

    /*
     * The figure this protects is time-to-first-contact, which is how an agency tells a slow sales team from a
     * bad campaign. If a merge could reset the clock, every merge would shorten it.
     */
    it('does not need to inherit an arrival time when the survivor is already the earlier', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00'),
            mergeCandidate('late', '2026-09-20 10:00:00'),
        );

        expect($plan->inheritsReceivedAt)->toBeFalse();
    });

    /*
     * The CRM owns its own records — the same position `ChangeLeadStage` takes. Merging one away would leave the
     * CRM syncing a record this product had stopped counting, and the two would disagree forever.
     */
    it('keeps the CRM lead even when it arrived second, and moves the age onto it', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('form', '2026-09-01 10:00:00'),
            mergeCandidate('deal', '2026-09-20 10:00:00', kind: LeadSourceKind::Crm),
        );

        expect($plan->survivor->id)->toBe('deal')
            ->and($plan->absorbed->id)->toBe('form')
            // The record changed, so the age has to move or the enquiry gets nineteen days younger.
            ->and($plan->inheritsReceivedAt)->toBeTrue();
    });
});

describe('what the survivor inherits', function (): void {
    it('takes a stronger attribution', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', evidence: AttributionEvidence::None),
            mergeCandidate('late', '2026-09-20 10:00:00', evidence: AttributionEvidence::ClickIdentifier),
        );

        expect($plan->survivor->id)->toBe('early')
            ->and($plan->inheritsAttribution)->toBeTrue();
    });

    it('leaves a weaker one alone', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', evidence: AttributionEvidence::ClickIdentifier),
            mergeCandidate('late', '2026-09-20 10:00:00', evidence: AttributionEvidence::UtmParameters),
        );

        expect($plan->inheritsAttribution)->toBeFalse();
    });

    /*
     * Equal proof is not an improvement. Swapping one campaign for another at the same level of evidence would
     * change which campaign a client is told produced the enquiry, for no reason anybody could give.
     */
    it('leaves an equally strong one alone', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', evidence: AttributionEvidence::UtmParameters),
            mergeCandidate('late', '2026-09-20 10:00:00', evidence: AttributionEvidence::UtmParameters),
        );

        expect($plan->inheritsAttribution)->toBeFalse();
    });
});

describe('the surviving stage', function (): void {
    it('accepts either lead’s own stage', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', stage: LeadStage::Contacted),
            mergeCandidate('late', '2026-09-20 10:00:00', stage: LeadStage::Won),
        );

        $plan->stageMustBeOneOfTheirs(LeadStage::Contacted);
        $plan->stageMustBeOneOfTheirs(LeadStage::Won);

        expect(true)->toBeTrue();
    });

    /*
     * A merge is not a pipeline move. A third stage here would qualify a lead with no qualifying event behind
     * it, and the review that asks "who decided this, and when" would find nothing.
     */
    it('refuses a stage neither lead is in, and names the two that are allowed', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', stage: LeadStage::New),
            mergeCandidate('late', '2026-09-20 10:00:00', stage: LeadStage::Contacted),
        );

        expect(fn () => $plan->stageMustBeOneOfTheirs(LeadStage::Qualified))
            ->toThrow(LeadsCannotBeMerged::class, 'New or Contacted, not Qualified');
    });

    it('says which lead holds the chosen stage, so its closing time travels with it', function (): void {
        $plan = LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00', stage: LeadStage::New),
            mergeCandidate('late', '2026-09-20 10:00:00', stage: LeadStage::Won),
        );

        expect($plan->stageIsHeldBy(LeadStage::Won)->id)->toBe('late')
            ->and($plan->stageIsHeldBy(LeadStage::New)->id)->toBe('early');
    });
});

describe('merges this product refuses', function (): void {
    it('refuses a lead merged into itself', function (): void {
        $lead = mergeCandidate('same', '2026-09-01 10:00:00');

        expect(fn () => LeadMergePlan::of($lead, $lead))
            ->toThrow(LeadsCannotBeMerged::class, 'cannot be merged into itself');
    });

    /* A chain would make "how many leads is this really" unanswerable. */
    it('refuses a lead that has already been merged away', function (): void {
        expect(fn () => LeadMergePlan::of(
            mergeCandidate('early', '2026-09-01 10:00:00'),
            mergeCandidate('gone', '2026-09-20 10:00:00', isAlreadyMerged: true),
        ))->toThrow(LeadsCannotBeMerged::class, 'merge that one instead');
    });

    /*
     * Both from a CRM means the CRM still holds two records afterwards, still syncs both, and the merge has to
     * be repeated after every sync while the product shows a number the CRM disagrees with.
     */
    it('refuses two CRM leads, and says where to merge them', function (): void {
        expect(fn () => LeadMergePlan::of(
            mergeCandidate('deal-a', '2026-09-01 10:00:00', kind: LeadSourceKind::Crm),
            mergeCandidate('deal-b', '2026-09-20 10:00:00', kind: LeadSourceKind::Crm),
        ))->toThrow(LeadsCannotBeMerged::class, 'still holding two records');
    });
});
