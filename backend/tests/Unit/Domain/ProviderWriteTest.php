<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteFailure;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Integration\WriteTarget;
use App\Domain\Operations\ApprovalKind;

/*
|--------------------------------------------------------------------------
| Writing to a provider
|--------------------------------------------------------------------------
|
| Every other integration in this product is a read, and a read that is wrong is a figure somebody questions. A
| write that is wrong is a client's campaign in a state nobody chose — so these tests are about the refusals.
|
| The three that matter most: a provider whose documentation this product could not read is **not** attempted;
| `unknown` is never safe to retry, because trying again after it is how a budget gets set twice; and a proposal
| that does not name a campaign id is refused rather than matched by name.
|
*/

describe('write support', function (): void {
    it('attempts only what was read in a provider\'s own documentation, at that operation\'s level', function (): void {
        /*
         * Every campaign-level change and every ad-group and ad status change the six platforms' own documentation
         * describes — each with the endpoint and fields that were read.
         */
        foreach ([Provider::Meta, Provider::Snapchat, Provider::TikTok, Provider::LinkedIn] as $provider) {
            foreach (WriteOperation::cases() as $operation) {
                expect($provider->writeSupport($operation))->toBe(WriteSupport::Documented);
                expect($provider->writeReference($operation))->toBeString()->not->toBe('');
            }
        }
    });

    it('never infers support at one level from another', function (): void {
        // Google Ads keeps budgets on a campaign budget resource: a campaign has one, an ad group has none.
        expect(Provider::GoogleAds->writeSupport(WriteOperation::SetCampaignDailyBudget))->toBe(WriteSupport::Documented)
            ->and(Provider::GoogleAds->writeSupport(WriteOperation::SetAdGroupDailyBudget))->toBe(WriteSupport::NotSupported)
            ->and(Provider::GoogleAds->writeSupport(WriteOperation::PauseAd))->toBe(WriteSupport::Documented);

        // X pauses a campaign and a line item, and says a promoted tweet cannot be updated at all.
        expect(Provider::X->writeSupport(WriteOperation::PauseCampaign))->toBe(WriteSupport::Documented)
            ->and(Provider::X->writeSupport(WriteOperation::PauseAdGroup))->toBe(WriteSupport::Documented)
            ->and(Provider::X->writeSupport(WriteOperation::PauseAd))->toBe(WriteSupport::NotSupported)
            ->and(Provider::X->writeReference(WriteOperation::PauseAd))->toBeNull()
            ->and(ProviderEntityWrites::notOffered(Provider::X, WriteOperation::PauseAd))->toContain('promoted Tweet');

        // No platform keeps a budget on an ad, so there is no such operation to ask about.
        expect(WriteOperation::at(AdEntityType::Ad, 'budget'))->toBeNull();
    });

    it('writes to the level a person approved: a LinkedIn campaign is this product\'s ad group', function (): void {
        expect(Provider::LinkedIn->writeReference(WriteOperation::PauseCampaign))->toContain('adCampaignGroups')
            ->and(Provider::LinkedIn->writeReference(WriteOperation::PauseAdGroup))->toContain('/adCampaigns/')
            ->and(Provider::LinkedIn->writeReference(WriteOperation::PauseAd))->toContain('creatives');
    });

    it('records each platform\'s bid field on the ad group, and offers it nowhere', function (): void {
        foreach ([Provider::Meta, Provider::GoogleAds, Provider::TikTok, Provider::Snapchat, Provider::X, Provider::LinkedIn] as $provider) {
            expect(ProviderEntityWrites::bid($provider, AdEntityType::AdGroup))->toBeString()
                ->and(ProviderEntityWrites::bid($provider, AdEntityType::Campaign))->toBeNull();
        }
    });

    it('reads the operation an approval authorises from its kind and its level', function (): void {
        expect(WriteOperation::forApproval(ApprovalKind::CampaignPause, []))->toBe(WriteOperation::PauseCampaign)
            ->and(WriteOperation::forApproval(ApprovalKind::CampaignPause, ['level' => 'ad']))->toBe(WriteOperation::PauseAd)
            ->and(WriteOperation::forApproval(ApprovalKind::SpendChange, ['level' => 'ad_group']))->toBe(WriteOperation::SetAdGroupDailyBudget)
            ->and(WriteOperation::forApproval(ApprovalKind::SpendChange, ['level' => 'ad']))->toBeNull();
    });

    it('writes to no analytics property, tag container or store', function (): void {
        /*
         * Reading what a property measured is the integration; changing somebody's measurement configuration
         * from a reporting tool is a hazard rather than a feature — the argument `capabilities()` already makes.
         */
        foreach ([Provider::GoogleAnalytics, Provider::GoogleTagManager, Provider::Salla, Provider::Zid] as $provider) {
            foreach (WriteOperation::cases() as $operation) {
                expect($provider->writeSupport($operation))->toBe(WriteSupport::NotSupported);
            }
        }
    });

    it('gives a reason a person can act on for every refusal', function (): void {
        expect(WriteSupport::Documented->because())->toBeNull();

        foreach ([WriteSupport::Unverified, WriteSupport::NotSupported] as $support) {
            /* Each ends with what to do instead, because the next thing somebody does is open the platform. */
            expect($support->because())->toContain('record it here');
        }
    });
});

describe('what authorises a write', function (): void {
    it('maps every operation to an approval kind', function (): void {
        expect(WriteOperation::PauseCampaign->authorisedBy())->toBe(ApprovalKind::CampaignPause);
        /* Resuming starts money moving again, so it is a launch rather than the reverse of a pause. */
        expect(WriteOperation::ResumeCampaign->authorisedBy())->toBe(ApprovalKind::CampaignLaunch);
        expect(WriteOperation::SetCampaignDailyBudget->authorisedBy())->toBe(ApprovalKind::SpendChange);
    });

    it('keeps approving separate from carrying out', function (): void {
        /*
         * Still false for every kind, and that is not an oversight: approving a change never makes it happen.
         * Carrying it out is a second act, by somebody holding `provider_writes.execute`.
         */
        foreach (ApprovalKind::cases() as $kind) {
            expect($kind->isEnactedByThisProduct())->toBeFalse();
        }
    });

    it('needs the capability the operation changes', function (): void {
        expect(WriteOperation::PauseCampaign->requires()->isWrite())->toBeTrue();
        expect(WriteOperation::SetCampaignDailyBudget->requires()->isWrite())->toBeTrue();

        /* A workspace with status mutation and not budget mutation cannot have the second applied to it. */
        expect(WriteOperation::PauseCampaign->requires())
            ->not->toBe(WriteOperation::SetCampaignDailyBudget->requires());
    });
});

describe('reading a target out of a proposal', function (): void {
    it('refuses a proposal that does not name a campaign', function (): void {
        /*
         * The failure this guard exists to prevent. "Pause the retail campaign" is not a campaign id, and a
         * product that matched it by name would eventually pause a different campaign with a similar name.
         */
        expect(WriteTarget::fromProposal(
            ['summary' => 'Pause the retail campaign'],
            WriteOperation::PauseCampaign,
        ))->toBeNull();
    });

    it('reads a complete proposal', function (): void {
        $target = WriteTarget::fromProposal([
            'provider' => 'meta',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
        ], WriteOperation::PauseCampaign);

        expect($target)->not->toBeNull();
        expect($target?->provider)->toBe(Provider::Meta);
        expect($target?->externalCampaignId)->toBe('23848238');
    });

    it('refuses a budget change with no amount', function (): void {
        /*
         * And does not default it. There is no sensible default for what a client spends, and the one somebody
         * would reach for — the current amount — makes the whole request a no-op that reports success.
         */
        expect(WriteTarget::fromProposal([
            'provider' => 'meta',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
        ], WriteOperation::SetCampaignDailyBudget))->toBeNull();

        expect(WriteTarget::fromProposal([
            'provider' => 'meta',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
            'daily_budget_minor' => 0,
        ], WriteOperation::SetCampaignDailyBudget))->toBeNull();
    });

    it('accepts an amount that arrived as a string from a form', function (): void {
        $target = WriteTarget::fromProposal([
            'provider' => 'meta',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
            'daily_budget_minor' => '50000',
        ], WriteOperation::SetCampaignDailyBudget);

        expect($target?->dailyBudgetMinor)->toBe(50_000);
    });

    it('refuses a pause that also carries a budget', function (): void {
        /*
         * A proposal describing two changes while asking approval for one. The fingerprint covers the whole
         * proposal, so this is not a security hole — it is a request nobody could read correctly, which is worse.
         */
        expect(WriteTarget::fromProposal([
            'provider' => 'meta',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
            'daily_budget_minor' => 50_000,
        ], WriteOperation::PauseCampaign))->toBeNull();
    });

    it('refuses a provider it does not recognise', function (): void {
        expect(WriteTarget::fromProposal([
            'provider' => 'some_network',
            'external_account_id' => 'act_1001',
            'external_campaign_id' => '23848238',
        ], WriteOperation::PauseCampaign))->toBeNull();
    });
});

describe('normalised failures', function (): void {
    it('never says an unknown outcome is safe to retry', function (): void {
        /*
         * The reason this case exists. A reader told "it failed" tries again; trying again after an unknown
         * outcome is how a budget gets set twice.
         */
        expect(WriteFailure::Unknown->isSafeToRetry())->toBeFalse();
        expect(WriteFailure::Unknown->guidance())->toContain('may have landed');

        /* And a rejection is not retried either: the provider has already said no. */
        expect(WriteFailure::Rejected->isSafeToRetry())->toBeFalse();
    });

    it('says what to do for every failure', function (): void {
        foreach (WriteFailure::cases() as $failure) {
            expect($failure->label())->not->toBe('');
            expect($failure->guidance())->not->toBe('');
        }
    });

    it('tells a reader nothing was changed, where nothing was', function (): void {
        foreach ([WriteFailure::NotAuthorised, WriteFailure::NotFound, WriteFailure::RateLimited, WriteFailure::Unavailable] as $failure) {
            expect($failure->guidance())->toContain('Nothing was changed');
            expect($failure->isSafeToRetry())->toBeTrue();
        }
    });
});
