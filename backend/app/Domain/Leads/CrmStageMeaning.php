<?php

declare(strict_types=1);

namespace App\Domain\Leads;

/**
 * What a CRM's own pipeline stage *means*, decided from evidence rather than from its name.
 *
 * This is the one rule the whole CRM bridge turns on, and it is a refusal more than a mapping.
 *
 * A CRM's stages are named by the people who use it: "Proposal sent", "Negotiation", "Mudawalat", "Stage 3",
 * "Hot". Reading a meaning out of a label is the mistake this product refuses everywhere else — a tag called
 * `purchase_confirmed` is not evidence of a purchase, and a stage called "Won" is not evidence of a sale. The
 * difference between a product that can be trusted with a client's revenue figures and one that cannot is
 * whether it guesses here.
 *
 * So the meaning comes from the **metadata the CRM publishes about the stage itself**. HubSpot's deal stages
 * carry `probability` (0.0 to 1.0) and `isClosed`, which are structured statements rather than names:
 *
 *  - closed at probability 1.0 → **the deal was won**;
 *  - closed at probability 0.0 → **the deal was lost**;
 *  - anything else → **open, and this product does not know more than that**.
 *
 * The third case is the important one. An open stage is not "qualified", not "contacted" and not anything else
 * in `LeadStage`: those are this product's own words for its own pipeline, and a CRM's middle stages do not map
 * onto them. A lead in one keeps whatever stage it already had, and the surface shows the **CRM's own label**
 * beside it, attributed to the CRM. Two vocabularies, both visible, neither pretending to be the other.
 */
enum CrmStageMeaning: string
{
    case Won = 'won';

    case Lost = 'lost';

    /** Open, and nothing more is claimed. */
    case Open = 'open';

    /**
     * What a closed/probability pair states.
     *
     * `$isClosed` and `$probability` are both nullable because a CRM may publish neither — a custom pipeline
     * with no metadata, or an object type that does not carry it. The answer is then `Open`, which is the only
     * honest one: "we cannot tell" and "it is still running" lead to the same behaviour here, and inventing a
     * third state would invite somebody to treat an unreadable stage as a negative one.
     */
    public static function fromStageMetadata(?bool $isClosed, ?float $probability): self
    {
        if ($isClosed !== true || $probability === null) {
            return self::Open;
        }

        /*
         * Exactly 1.0 and exactly 0.0, not thresholds. A closed stage at 0.9 is not a documented state — HubSpot
         * sets 1.0 for closed-won and 0.0 for closed-lost — and treating 0.9 as a win would be this product
         * deciding something the CRM did not say.
         */
        if ($probability === 1.0) {
            return self::Won;
        }

        return $probability === 0.0 ? self::Lost : self::Open;
    }

    /**
     * The stage this product should record, or null to leave a lead where it is.
     *
     * Null for `Open`, and that is not a gap: `LeadStage` is this product's vocabulary for its own pipeline, and
     * a CRM's middle stages do not map onto it. Overwriting a stage somebody set here with a guess from a label
     * would be worse than leaving it alone.
     */
    public function stage(): ?LeadStage
    {
        return match ($this) {
            self::Won => LeadStage::Won,
            self::Lost => LeadStage::Lost,
            self::Open => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Won => 'Won in the CRM',
            self::Lost => 'Lost in the CRM',
            self::Open => 'Open in the CRM',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
