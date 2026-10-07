<?php

declare(strict_types=1);

use App\Application\Attribution\DTO\AttributedCampaignRow;
use App\Application\Attribution\DTO\ModelDisagreement;
use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Attribution\AttributionRequirement;
use App\Domain\Attribution\CampaignLink;
use App\Domain\Commerce\AttributionEvidence;

/*
|--------------------------------------------------------------------------
| Attribution
|--------------------------------------------------------------------------
|
| Attribution is the part of this product most able to produce a confident figure nobody can defend.
| Every test here is about a refusal: a model that will not be computed, a rate with no denominator, a
| ROAS that will not be divided, a spread that will not be hidden.
|
*/

describe('what a model admits about itself', function (): void {
    it('calls everything except the recorded campaign a model', function (): void {
        /*
         * The field every surface showing one of these figures is obliged to act on. Exactly one
         * member is false, because exactly one reports what the order said rather than deciding what
         * it meant.
         */
        expect(AttributionModel::LastRecorded->isModelled())->toBeFalse()
            ->and(AttributionModel::FirstRecorded->isModelled())->toBeTrue()
            ->and(AttributionModel::PaidLastClick->isModelled())->toBeTrue()
            ->and(AttributionModel::Linear->isModelled())->toBeTrue();
    });

    it('refuses the models that need a touch path, and says what would unblock them', function (): void {
        foreach ([AttributionModel::Linear, AttributionModel::PositionBased, AttributionModel::TimeDecay] as $model) {
            /*
             * Declared and unavailable, deliberately. Leaving them out of the enum would mean the
             * interface could not explain their absence, and the question would be asked again every
             * quarter; computing something shaped like them from one recorded touch would produce a
             * number named after a model it is not.
             */
            expect($model->isAvailable())->toBeFalse()
                ->and($model->requires())->toBe(AttributionRequirement::TouchPath)
                ->and($model->requires()->missing())->toContain('not available to this product')
                ->and($model->requires()->wouldUnblock())->toContain('BigQuery');
        }
    });

    it('offers only the models it can compute', function (): void {
        $available = array_map(
            static fn (AttributionModel $model): string => $model->value,
            AttributionModel::available(),
        );

        expect($available)->toBe(['last_recorded', 'first_recorded', 'paid_last_click']);
    });

    it('knows which models need to know who the customer is', function (): void {
        /*
         * A coverage limit rather than a failure: an order with no contact details cannot be looked
         * back from, and the surface reports that instead of silently crediting the order's own
         * campaign as though the history had been checked.
         */
        expect(AttributionModel::FirstRecorded->needsPerson())->toBeTrue()
            ->and(AttributionModel::LastRecorded->needsPerson())->toBeFalse()
            ->and(AttributionModel::PaidLastClick->needsPerson())->toBeFalse();
    });
});

describe('coverage, which stops a share being read as a total', function (): void {
    /** @param array<string, int|string|null> $overrides */
    function coverage(array $overrides = []): AttributionCoverage
    {
        return new AttributionCoverage(
            conversions: (int) ($overrides['conversions'] ?? 100),
            attributedConversions: (int) ($overrides['attributedConversions'] ?? 40),
            campaignNamedConversions: (int) ($overrides['campaignNamedConversions'] ?? 30),
            revenueMinor: array_key_exists('revenueMinor', $overrides)
                ? (is_int($overrides['revenueMinor']) ? $overrides['revenueMinor'] : null)
                : 1_000_000,
            attributedRevenueMinor: array_key_exists('attributedRevenueMinor', $overrides)
                ? (is_int($overrides['attributedRevenueMinor']) ? $overrides['attributedRevenueMinor'] : null)
                : 300_000,
            currency: array_key_exists('currency', $overrides)
                ? (is_string($overrides['currency']) ? $overrides['currency'] : null)
                : 'SAR',
        );
    }

    it('publishes the revenue nobody can be credited with as its own figure', function (): void {
        $c = coverage(['revenueMinor' => 1_000_000, 'attributedRevenueMinor' => 300_000]);

        /*
         * The number a reader most needs and least expects. Left to subtraction it goes uncomputed,
         * and the 300,000 gets quoted as the month.
         */
        expect($c->unattributedRevenueMinor())->toBe(700_000)
            ->and($c->revenueCoverage())->toBe(30.0);
    });

    it('will not call a 30% share reportable', function (): void {
        expect(coverage()->isReportable())->toBeFalse();
    });

    it('calls a well-tagged account reportable', function (): void {
        $c = coverage(['revenueMinor' => 1_000_000, 'attributedRevenueMinor' => 750_000]);

        // Seventy rather than ninety: a threshold nobody can reach is one everybody learns to ignore.
        expect($c->isReportable())->toBeTrue();
    });

    it('gives null, not zero, for every rate when there is nothing to divide by', function (): void {
        $empty = new AttributionCoverage(
            conversions: 0,
            attributedConversions: 0,
            campaignNamedConversions: 0,
            revenueMinor: 0,
            attributedRevenueMinor: 0,
            currency: 'SAR',
        );

        /*
         * `0%` coverage and "no orders in this period" look identical on a card and mean opposite
         * things — the first says tagging is broken, the second says nothing happened.
         */
        expect($empty->conversionCoverage())->toBeNull()
            ->and($empty->revenueCoverage())->toBeNull()
            ->and($empty->campaignNamedShare())->toBeNull()
            ->and($empty->isReportable())->toBeFalse();
    });

    it('withholds every money figure when the currency is unknown', function (): void {
        $mixed = coverage(['revenueMinor' => null, 'attributedRevenueMinor' => null, 'currency' => null]);

        // A sum of minor units across currencies is not a figure. The counts survive; the money does not.
        expect($mixed->unattributedRevenueMinor())->toBeNull()
            ->and($mixed->revenueCoverage())->toBeNull()
            // And it falls back to the conversion coverage rather than claiming nothing is known.
            ->and($mixed->conversionCoverage())->toBe(40.0);
    });

    it('separates naming a campaign from naming a channel', function (): void {
        $c = coverage(['attributedConversions' => 40, 'campaignNamedConversions' => 30]);

        /*
         * A referrer or a bare `utm_source` names a channel. Splitting that revenue across the
         * channel's campaigns would be inventing the split, so those rows are counted and reported
         * rather than distributed.
         */
        expect($c->campaignNamedShare())->toBe(75.0);
    });
});

describe('a row that must not invent a ratio', function (): void {
    /** @param array<string, mixed> $overrides */
    function row(array $overrides = []): AttributedCampaignRow
    {
        return new AttributedCampaignRow(
            label: 'autumn-range',
            link: ($overrides['link'] ?? null) instanceof CampaignLink
                ? $overrides['link']
                : CampaignLink::ExactName,
            conversions: (int) ($overrides['conversions'] ?? 10),
            revenueMinor: array_key_exists('revenueMinor', $overrides)
                ? (is_int($overrides['revenueMinor']) ? $overrides['revenueMinor'] : null)
                : 500_000,
            currency: 'SAR',
            evidence: AttributionEvidence::UtmParameters,
            spendMinor: array_key_exists('spendMinor', $overrides)
                ? (is_int($overrides['spendMinor']) ? $overrides['spendMinor'] : null)
                : 100_000,
        );
    }

    it('divides revenue by spend when both belong to the same row', function (): void {
        expect(row()->roas())->toBe(5.0)
            ->and(row()->costPerConversionMinor())->toBe(10_000);
    });

    it('gives no ROAS for a campaign with revenue and no spend', function (): void {
        /*
         * The case an infinite ROAS would report as the month's triumph. Revenue with no spend means
         * the money arrived and nobody can say what was paid for it.
         */
        expect(row(['spendMinor' => null])->roas())->toBeNull()
            ->and(row(['spendMinor' => 0])->roas())->toBeNull();
    });

    it('refuses to divide across two different things', function (): void {
        $unmatched = row(['link' => CampaignLink::NoMatchingCampaign, 'spendMinor' => 100_000]);

        /*
         * Only an exact campaign-name match puts spend and revenue on one row. Anything looser and
         * the two numbers belong to different campaigns, so the ratio between them is meaningless
         * however plausible it looks.
         */
        expect($unmatched->roas())->toBeNull()
            ->and($unmatched->costPerConversionMinor())->toBeNull();
    });

    it('explains an empty spend cell rather than leaving it blank', function (): void {
        expect(CampaignLink::ClickNotResolved->explanation())->toContain('click-level API')
            ->and(CampaignLink::NoMatchingCampaign->explanation())->toContain('renamed or deleted')
            ->and(CampaignLink::ChannelOnly->explanation())->toContain('inventing the split')
            // A matched row needs no explanation; the spend is simply there.
            ->and(CampaignLink::ExactName->explanation())->toBeNull();
    });

    it('lets only an exact name match carry spend', function (): void {
        expect(CampaignLink::ExactName->joinsSpend())->toBeTrue()
            ->and(CampaignLink::ClickNotResolved->joinsSpend())->toBeFalse()
            ->and(CampaignLink::NoMatchingCampaign->joinsSpend())->toBeFalse()
            ->and(CampaignLink::ChannelOnly->joinsSpend())->toBeFalse();
    });
});

describe('how much the choice of model is worth', function (): void {
    it('measures the spread against the largest figure', function (): void {
        $row = new ModelDisagreement('autumn-range', [
            'last_recorded' => 500_000,
            'first_recorded' => 100_000,
        ], 'SAR');

        /*
         * Of the largest rather than the smallest, so a campaign one model credits with nothing reads
         * as 100% disagreement instead of an infinity.
         */
        expect($row->spreadPercent())->toBe(80.0)
            ->and($row->isMaterial())->toBeTrue();
    });

    it('calls a small disagreement immaterial', function (): void {
        $row = new ModelDisagreement('steady', ['last_recorded' => 100_000, 'first_recorded' => 95_000]);

        // Below a fifth the choice of model is a detail; above it, the choice is the story.
        expect($row->spreadPercent())->toBe(5.0)
            ->and($row->isMaterial())->toBeFalse();
    });

    it('gives no spread for a single model', function (): void {
        $row = new ModelDisagreement('only-one', ['last_recorded' => 100_000]);

        // A spread of zero and "only one model ran" are different facts.
        expect($row->spreadPercent())->toBeNull()
            ->and($row->isMaterial())->toBeFalse();
    });

    it('ignores a model that did not report the campaign at all', function (): void {
        $row = new ModelDisagreement('tail-row', [
            'last_recorded' => 80_000,
            'first_recorded' => null,
        ]);

        /*
         * Null is not zero. A campaign missing from one model's rows may only have fallen outside the
         * top rows of a limited breakdown, and treating that as "this model gave it nothing" would
         * manufacture a disagreement.
         */
        expect($row->highestMinor())->toBe(80_000)
            ->and($row->lowestMinor())->toBe(80_000)
            ->and($row->spreadPercent())->toBe(0.0);
    });
});
