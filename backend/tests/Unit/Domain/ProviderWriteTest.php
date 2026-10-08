<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
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
    it('attempts only what was read in a provider\'s own documentation', function (): void {
        /*
         * Meta, Google Ads, Snapchat and LinkedIn: each operation was read in the provider's own current
         * documentation, and `writeReference()` records the endpoint and the fields.
         */
        foreach ([Provider::Meta, Provider::GoogleAds, Provider::Snapchat, Provider::LinkedIn] as $provider) {
            foreach (WriteOperation::cases() as $operation) {
                expect($provider->writeSupport($operation))->toBe(WriteSupport::Documented);
                expect($provider->writeReference($operation))->toBeString();
                expect($provider->writeReference($operation))->not->toBe('');
            }
        }
    });

    it('refuses a provider whose documentation could not be read', function (): void {
        /*
         * TikTok publishes its Business API through a portal that serves no readable content to a fetcher, and
         * X's developer documentation was not reachable. Both operations are described in third-party libraries,
         * which is not the provider saying so — and a guess here is a guess against a live campaign.
         */
        foreach ([Provider::TikTok, Provider::X] as $provider) {
            foreach (WriteOperation::cases() as $operation) {
                expect($provider->writeSupport($operation))->toBe(WriteSupport::Unverified);
                expect($provider->writeSupport($operation)->allowsAttempt())->toBeFalse();
                /* No reference, because nothing was read. */
                expect($provider->writeReference($operation))->toBeNull();
            }
        }
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
