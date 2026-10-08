<?php

declare(strict_types=1);

namespace App\Domain\Leads;

use App\Domain\Leads\Exception\LeadsCannotBeMerged;

/**
 * Which of two leads survives a merge, and what it inherits.
 *
 * The roadmap recorded this operation as needing "a decision about whose history survives". The decision came
 * apart into four, and each has an answer this product's own rules already imply.
 *
 * **Both histories survive.** `lead_events` move to the survivor rather than being discarded, and the absorbed
 * record is kept and marked. Nothing is deleted, because a client may already have been told a count and
 * "the number changed" is only an answer if the change is written down.
 *
 * **The earlier arrival's age survives, whichever record does.** Time-to-first-contact and pipeline duration
 * are measured from `received_at`, and they are the figures that tell a slow sales team from a bad campaign. If
 * a merge could reset the clock, every merge would shorten those durations and flatter whoever did the merging.
 * So the survivor always takes `min(received_at)` — the record may change, the age may not.
 *
 * **A CRM-sourced lead is always the survivor.** The same position `ChangeLeadStage` takes: the system a lead
 * came from owns it. Merging one away would leave the CRM syncing a record this product had stopped counting,
 * and the two would disagree forever with nobody able to see why. Two CRM leads cannot be merged here at all —
 * that belongs in the CRM, which holds both.
 *
 * **The stronger attribution survives, wholly.** Exactly the rule the repository already applies to a
 * re-arrival: a half-merged attribution — this lead's click id beside the other's campaign — is a link to a
 * campaign nobody can verify, which is what the evidence levels exist to prevent.
 *
 * The one thing this class will not decide is the surviving **stage**. A stage is a human judgement — a lead
 * that bought and a lead that enquired again are both real, and which one the merged record is in is not
 * derivable from either. The caller supplies it, and it must be a stage one of the two is already in.
 */
final readonly class LeadMergePlan
{
    private function __construct(
        public LeadMergeCandidate $survivor,
        public LeadMergeCandidate $absorbed,
        /**
         * Whether the survivor must adopt the absorbed lead's arrival time.
         *
         * True exactly when the survivor was chosen for a reason other than being first — today, that it came
         * from a CRM. The merge is then the only moment the product can keep the real age of the enquiry.
         */
        public bool $inheritsReceivedAt,
        /** Whether the absorbed lead's attribution block replaces the survivor's, wholly or not at all. */
        public bool $inheritsAttribution,
    ) {}

    public static function of(LeadMergeCandidate $first, LeadMergeCandidate $second): self
    {
        if ($first->id === $second->id) {
            throw LeadsCannotBeMerged::theSameLead();
        }

        if ($first->isAlreadyMerged || $second->isAlreadyMerged) {
            throw LeadsCannotBeMerged::alreadyMerged();
        }

        $firstIsCrm = $first->sourceKind === LeadSourceKind::Crm;
        $secondIsCrm = $second->sourceKind === LeadSourceKind::Crm;

        if ($firstIsCrm && $secondIsCrm) {
            throw LeadsCannotBeMerged::bothOwnedByTheCrm($first->sourceKind->label());
        }

        [$survivor, $absorbed] = self::order($first, $second, $firstIsCrm, $secondIsCrm);

        return new self(
            survivor: $survivor,
            absorbed: $absorbed,
            inheritsReceivedAt: $absorbed->receivedAt < $survivor->receivedAt,
            /*
             * Strictly stronger. Equal evidence leaves the survivor's own block alone: two leads at the same
             * level of proof are not an improvement on each other, and swapping one for the other would change
             * a client's campaign report for no reason anybody could give.
             */
            inheritsAttribution: $absorbed->evidence->confidence() > $survivor->evidence->confidence(),
        );
    }

    /**
     * The stage the merged lead keeps, checked against the only two it may be.
     *
     * Not a free choice: a merge is not a place to move a lead through a pipeline. Allowing a third stage would
     * let somebody qualify a lead with no qualifying event behind it, and the pipeline review that asks "who
     * decided this, and when" would find nothing.
     */
    public function stageMustBeOneOfTheirs(LeadStage $chosen): void
    {
        if ($chosen !== $this->survivor->stage && $chosen !== $this->absorbed->stage) {
            throw LeadsCannotBeMerged::stageIsNeitherOfTheirs(
                $chosen->label(),
                $this->survivor->stage->label(),
                $this->absorbed->stage->label(),
            );
        }
    }

    /**
     * Which of the two leads holds the chosen stage, so its closing time travels with it.
     *
     * The schema requires a closed lead to carry a `closed_at` and an open one not to. The time is never
     * invented here: the lead that is already in the chosen stage has a valid one, and taking it is the
     * difference between "closed on the third" and "closed whenever somebody merged two records".
     */
    public function stageIsHeldBy(LeadStage $chosen): LeadMergeCandidate
    {
        return $this->survivor->stage === $chosen ? $this->survivor : $this->absorbed;
    }

    /** @return array{LeadMergeCandidate, LeadMergeCandidate} */
    private static function order(
        LeadMergeCandidate $first,
        LeadMergeCandidate $second,
        bool $firstIsCrm,
        bool $secondIsCrm,
    ): array {
        if ($firstIsCrm) {
            return [$first, $second];
        }

        if ($secondIsCrm) {
            return [$second, $first];
        }

        /* Neither is owned elsewhere, so the first arrival is the record that keeps going. */
        return $first->receivedAt <= $second->receivedAt ? [$first, $second] : [$second, $first];
    }
}
