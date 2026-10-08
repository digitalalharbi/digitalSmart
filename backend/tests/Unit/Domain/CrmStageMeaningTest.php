<?php

declare(strict_types=1);

use App\Domain\Leads\CrmStageMeaning;
use App\Domain\Leads\LeadStage;

/*
|--------------------------------------------------------------------------
| What a CRM stage means
|--------------------------------------------------------------------------
|
| The whole CRM bridge turns on this, and it is a refusal more than a mapping.
|
| A CRM's stages are named by the people using it: "Proposal sent", "Mudawalat", "Stage 3", "Hot". Reading a
| meaning out of a label is the mistake this product refuses everywhere else — a tag called `purchase_confirmed`
| is not evidence of a purchase — and the difference between a product that can be trusted with a client's
| revenue and one that cannot is whether it guesses here.
|
*/

describe('a stage this product can read', function (): void {
    it('reads a win from closed at probability one', function (): void {
        $meaning = CrmStageMeaning::fromStageMetadata(true, 1.0);

        expect($meaning)->toBe(CrmStageMeaning::Won);
        expect($meaning->stage())->toBe(LeadStage::Won);
    });

    it('reads a loss from closed at probability zero', function (): void {
        $meaning = CrmStageMeaning::fromStageMetadata(true, 0.0);

        expect($meaning)->toBe(CrmStageMeaning::Lost);
        expect($meaning->stage())->toBe(LeadStage::Lost);
    });
});

describe('a stage this product will not interpret', function (): void {
    it('leaves an open stage open, whatever its probability', function (): void {
        foreach ([0.2, 0.5, 0.9, 1.0] as $probability) {
            /*
             * Not closed means open, even at 1.0. A pipeline where somebody set a stage called "Won" to
             * probability 0.9 without marking it closed is an ordinary pipeline, and this is the case that makes
             * the rule worth having.
             */
            expect(CrmStageMeaning::fromStageMetadata(false, $probability))->toBe(CrmStageMeaning::Open);
        }
    });

    it('refuses to read a win out of a closed stage at any other probability', function (): void {
        /*
         * Exact values, not thresholds. HubSpot documents 1.0 for closed-won and 0.0 for closed-lost; a closed
         * stage at 0.9 is not a documented state, and treating it as a win would be this product deciding
         * something the CRM did not say.
         */
        expect(CrmStageMeaning::fromStageMetadata(true, 0.9))->toBe(CrmStageMeaning::Open);
        expect(CrmStageMeaning::fromStageMetadata(true, 0.5))->toBe(CrmStageMeaning::Open);
    });

    it('treats metadata it cannot read as open rather than as a negative', function (): void {
        /*
         * A custom pipeline with no metadata, or an object type that does not carry it. "We cannot tell" and "it
         * is still running" lead to the same behaviour, and inventing a third state would invite somebody to
         * treat an unreadable stage as a lost one.
         */
        expect(CrmStageMeaning::fromStageMetadata(null, null))->toBe(CrmStageMeaning::Open);
        expect(CrmStageMeaning::fromStageMetadata(true, null))->toBe(CrmStageMeaning::Open);
        expect(CrmStageMeaning::fromStageMetadata(null, 1.0))->toBe(CrmStageMeaning::Open);
    });

    it('maps an open stage to no stage of its own', function (): void {
        /*
         * Null, not `Qualified` and not `Contacted`. Those are this product's words for its own pipeline, and a
         * CRM's middle stages do not map onto them — so a lead in one keeps the stage it already had.
         */
        expect(CrmStageMeaning::Open->stage())->toBeNull();
    });
});
