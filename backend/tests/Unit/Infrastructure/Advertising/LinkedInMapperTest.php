<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\LinkedInMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| LinkedIn normalisation
|--------------------------------------------------------------------------
|
| The sixth set of conventions, and the one where the *names* are the hazard: a
| LinkedIn "campaign" is an ad set, so a campaign group maps to our campaign and a
| campaign to our ad group. Money is a decimal string in an object rather than micros,
| schedules are epoch milliseconds, ids are URNs in some places and longs in others,
| and `servingStatuses` rather than `status` decides whether anything is delivering.
|
*/

function linkedInFixtures(string $today = '2026-10-06'): SandboxFixtures
{
    return new SandboxFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable($today));
}

/** @return array<string, mixed> */
function linkedInRow(string $file, string $id): array
{
    foreach (linkedInFixtures()->rows(Provider::LinkedIn, $file) as $row) {
        if ((string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row with id {$id}.");
}

/** @return array<string, mixed> */
function linkedInInsight(string $file, string $urn): array
{
    foreach (linkedInFixtures()->rows(Provider::LinkedIn, $file) as $row) {
        $values = $row['pivotValues'] ?? [];

        if (is_array($values) && ($values[0] ?? null) === $urn) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row pivoted on {$urn}.");
}

describe('campaign groups', function (): void {
    it('maps a campaign group onto our campaign level', function (): void {
        $campaign = (new LinkedInMapper)->campaign(linkedInRow('campaign_groups', '603407684'), 'SAR');

        /*
         * The name collision that matters: LinkedIn's campaign *group* is the budget
         * and schedule container, so it is our campaign — and its children, which
         * LinkedIn calls campaigns, are our ad groups.
         */
        expect($campaign->externalId)->toBe('603407684')
            ->and($campaign->objective)->toBe(CampaignObjective::Leads)
            ->and($campaign->rawObjective)->toBe('LEAD_GENERATION')
            ->and($campaign->buyingType)->toBe('AUCTION')
            ->and($campaign->bidStrategy)->toBe('MAXIMUM_DELIVERY');
    });

    it('reads a decimal budget string into minor units', function (): void {
        $campaign = (new LinkedInMapper)->campaign(linkedInRow('campaign_groups', '603407684'), 'SAR');

        // "180000.00" SAR, not micros and not a bare number — the third money
        // convention across six providers.
        expect($campaign->budgetMinor)->toBe(18_000_000)
            ->and($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('reads an epoch-millisecond schedule', function (): void {
        $campaign = (new LinkedInMapper)->campaign(linkedInRow('campaign_groups', '603407684'), 'SAR');

        // 1753000000000 ms is 2025-07-20. Read as seconds it would be the year 57560.
        expect($campaign->startsAt?->format('Y-m-d'))->toBe('2025-07-20')
            ->and($campaign->endsAt)->toBeNull()
            ->and($campaign->providerCreatedAt?->format('Y'))->toBe('2025');
    });

    it('calls an active group on budget hold not delivering', function (): void {
        $campaign = (new LinkedInMapper)->campaign(linkedInRow('campaign_groups', '603407690'), 'SAR');

        /*
         * `status` is what the advertiser set; `servingStatuses` is service-controlled
         * and RUNNABLE is its only serving value. An ACTIVE group that has spent its
         * budget is not delivering, and the hold itself reaches the interface.
         */
        expect($campaign->status)->toBe(EntityStatus::NotDelivering)
            ->and($campaign->rawStatus)->toBe('ACTIVE')
            ->and($campaign->rawEffectiveStatus)->toBe('CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD');
    });

    it('records the default group as one that cannot be edited', function (): void {
        $campaign = (new LinkedInMapper)->campaign(linkedInRow('campaign_groups', '504293601'), 'SAR');

        // LinkedIn makes one group per account automatically; an enterprise account
        // cannot change it through the API, which an operator needs to be told.
        expect($campaign->metadata['backfilled'] ?? null)->toBeTrue()
            ->and($campaign->status)->toBe(EntityStatus::Paused)
            ->and($campaign->budgetMode)->toBe(BudgetMode::Inherited);
    });

    it('maps every documented status, and refuses to guess at a new one', function (): void {
        $mapper = new LinkedInMapper;
        $status = static fn (string $value): EntityStatus => $mapper->campaign(
            ['id' => 1, 'status' => $value],
            'SAR',
        )->status;

        expect($status('ACTIVE'))->toBe(EntityStatus::Active)
            ->and($status('PAUSED'))->toBe(EntityStatus::Paused)
            ->and($status('ARCHIVED'))->toBe(EntityStatus::Archived)
            ->and($status('COMPLETED'))->toBe(EntityStatus::Completed)
            ->and($status('DRAFT'))->toBe(EntityStatus::NotDelivering)
            /*
             * Removed rather than completed: LinkedIn's CANCELED is permanent and not
             * re-activatable, and "completed" would say it ran its course.
             */
            ->and($status('CANCELLED'))->toBe(EntityStatus::Removed)
            ->and($status('PENDING_DELETION'))->toBe(EntityStatus::Removed)
            ->and($status('SOMETHING_NEW'))->toBe(EntityStatus::Unknown);
    });
});

describe('campaigns as ad groups', function (): void {
    it('links a campaign to its group through a URN', function (): void {
        $adGroup = (new LinkedInMapper)->adGroup(linkedInRow('campaigns', '360035215'), 'SAR');

        // The parent arrives as `urn:li:sponsoredCampaignGroup:603407684`; what a later
        // request and a stored row both need is the trailing id.
        expect($adGroup->externalId)->toBe('360035215')
            ->and($adGroup->campaignExternalId)->toBe('603407684');
    });

    it('reads cost type as the billing event and the optimisation target as the goal', function (): void {
        $adGroup = (new LinkedInMapper)->adGroup(linkedInRow('campaigns', '360035215'), 'SAR');

        expect($adGroup->billingEvent)->toBe('CPC')
            ->and($adGroup->optimizationGoal)->toBe('MAX_LEAD')
            ->and($adGroup->metadata['objective_type'] ?? null)->toBe('LEAD_GENERATION')
            // The ad format is reported here, on the campaign, and not on the creative.
            ->and($adGroup->metadata['format'] ?? null)->toBe('STANDARD_UPDATE');
    });

    it('treats a zero unit cost as no bid rather than a bid of nothing', function (): void {
        $mapper = new LinkedInMapper;

        // Zero is LinkedIn's documented default under auto-bidding.
        expect($mapper->adGroup(linkedInRow('campaigns', '360035215'), 'SAR')->bidAmountMinor)->toBeNull()
            // "42.50" SAR is 4,250 halalas.
            ->and($mapper->adGroup(linkedInRow('campaigns', '360035221'), 'SAR')->bidAmountMinor)->toBe(4_250);
    });

    it('keeps the targeting tree as sent', function (): void {
        $adGroup = (new LinkedInMapper)->adGroup(linkedInRow('campaigns', '360035215'), 'SAR');

        /*
         * LinkedIn's targeting *is* on the campaign, unlike X's — a boolean tree of
         * include and exclude facets. Flattening it would lose the operators that
         * define the audience.
         */
        expect($adGroup->targeting)->toHaveKeys(['include', 'exclude']);
    });
});

describe('creatives as ads', function (): void {
    it('reaches the hierarchy through the campaign', function (): void {
        $ad = (new LinkedInMapper)->ad(linkedInRow('creatives', 'urn:li:sponsoredCreative:119962155'));

        /*
         * A creative names its campaign and nothing above it, so the campaign group is
         * left empty rather than guessed — the store resolves it from the campaign it
         * already synced.
         */
        expect($ad->externalId)->toBe('119962155')
            ->and($ad->adGroupExternalId)->toBe('360035215')
            ->and($ad->campaignExternalId)->toBe('')
            // The creative *is* the ad here, so the two ids are one.
            ->and($ad->creativeExternalId)->toBe('119962155');
    });

    it('labels a creative by the post it sponsors when it has no name', function (): void {
        $ad = (new LinkedInMapper)->ad(linkedInRow('creatives', 'urn:li:sponsoredCreative:119962160'));

        // `name` is optional and most creatives made in Campaign Manager have none.
        expect($ad->name)->toBe('Sponsored share 6778045555198214150');
    });

    it('lets a rejection override the switch, and carries the reason', function (): void {
        $ad = (new LinkedInMapper)->ad(linkedInRow('creatives', 'urn:li:sponsoredCreative:119962171'));

        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->rawStatus)->toBe('ACTIVE')
            ->and($ad->reviewFeedback)->toBe('CONTENT_POLICY_VIOLATION');
    });

    it('calls an approved creative held by its parent not delivering', function (): void {
        $ad = (new LinkedInMapper)->ad(linkedInRow('creatives', 'urn:li:sponsoredCreative:119962180'));

        /*
         * Approved, intended active, and still not serving — because the campaign group
         * above it is out of budget. The reason is the parent's, and the creative's row
         * has to say so rather than claim to be running.
         */
        expect($ad->status)->toBe(EntityStatus::NotDelivering)
            ->and($ad->rawEffectiveStatus)->toBe('APPROVED')
            ->and($ad->metadata['serving_hold_reasons'] ?? null)->toContain('CAMPAIGN_GROUP_TOTAL_BUDGET_HOLD');
    });

    it('treats a pending review as in review rather than refused', function (): void {
        $ad = (new LinkedInMapper)->ad([
            'id' => 'urn:li:sponsoredCreative:1',
            'campaign' => 'urn:li:sponsoredCampaign:2',
            'intendedStatus' => 'ACTIVE',
            'review' => ['status' => 'PENDING'],
        ]);

        expect($ad->status)->toBe(EntityStatus::InReview);
    });

    it('reports no creative format, because LinkedIn puts it on the campaign', function (): void {
        $creative = (new LinkedInMapper)->creative(linkedInRow('creatives', 'urn:li:sponsoredCreative:119962155'));

        /*
         * Not an omission. A creative points at a post, and the medium, words and link
         * all live on that post — a different resource and another request. The
         * campaign's own `format` is kept where it was reported.
         */
        expect($creative->format)->toBe(CreativeFormat::Unknown)
            ->and($creative->title)->toBeNull()
            ->and($creative->destinationUrl)->toBeNull()
            ->and($creative->metadata['content_reference'] ?? null)->toBe('urn:li:share:6778045555198214144');
    });
});

describe('analytics', function (): void {
    it('reads the entity out of the pivot values', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        // The row says which entity it is about only through `pivotValues`, which is an
        // array because a request may pivot on up to three things.
        expect($record->externalEntityId)->toBe('603407684');
    });

    it('reads a date made of three integers', function (): void {
        $record = (new LinkedInMapper)->insight(
            [
                'pivotValues' => ['urn:li:sponsoredCampaign:360035215'],
                'dateRange' => [
                    'start' => ['year' => 2026, 'month' => 9, 'day' => 7],
                    'end' => ['year' => 2026, 'month' => 9, 'day' => 7],
                ],
                'impressions' => 10,
            ],
            AdEntityType::AdGroup,
            'SAR',
        );

        // LinkedIn sends no date string anywhere: a Date is {year, month, day}.
        expect($record->date->format('Y-m-d'))->toBe('2026-09-07');
    });

    it('rounds a cost carrying more than two decimals', function (): void {
        $record = (new LinkedInMapper)->insight(
            [
                'pivotValues' => ['urn:li:sponsoredCampaign:360035215'],
                'dateRange' => ['start' => ['year' => 2026, 'month' => 9, 'day' => 7]],
                'costInLocalCurrency' => '19.91833',
            ],
            AdEntityType::AdGroup,
            'SAR',
        );

        // LinkedIn's own example figure. Rounded half-up at the minor unit, as their
        // billing does; truncating would lose a halala on every row.
        expect($record->spendMinor)->toBe(1_992);
    });

    it('reports conversion value as revenue, which LinkedIn does publish', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        // Unlike X, LinkedIn reports what the conversions were worth — one total for
        // the row rather than a figure per type, which is why it is revenue and not a
        // value on a breakdown row.
        expect($record->revenueMinor)->toBeGreaterThan(0)
            ->and($record->conversions)->toBeGreaterThan(0);
    });

    it('keeps reach below impressions and does not derive it', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        // `approximateMemberReach` is deduplicated members, and only reported for
        // ranges of 92 days or less.
        expect($record->reach)->toBeLessThan((int) $record->impressions)
            ->and($record->reach)->toBeGreaterThan(0);
    });

    it('keeps landing page clicks distinct from clicks', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        expect($record->linkClicks)->toBeLessThan((int) $record->clicks)
            ->and($record->linkClicks)->toBeGreaterThan(0);
    });

    it('leaves a metric LinkedIn did not report null', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407690'),
            AdEntityType::Campaign,
            'SAR',
        );

        /*
         * A video-views group has no landing page to click through to and no conversion
         * columns at all, so LinkedIn omits them. Zero would say nobody clicked, which
         * is a different and false claim.
         */
        expect($record->linkClicks)->toBeNull()
            ->and($record->conversions)->toBeNull()
            ->and($record->conversionBreakdown)->toBe([])
            ->and($record->videoViews)->toBeGreaterThan(0);
    });

    it('writes a conversion row per reported column, with no value on any', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        $types = array_map(static fn ($c): string => $c->rawActionType, $record->conversionBreakdown);

        expect($types)->toContain('externalWebsiteConversions', 'oneClickLeads')
            /*
             * `conversionValueInLocalCurrency` is one total for the row, not a figure
             * per conversion type. Attaching it to one of these would invent an
             * attribution LinkedIn never made.
             */
            ->and(array_filter(
                $record->conversionBreakdown,
                static fn ($c): bool => $c->valueMinor !== null,
            ))->toBe([]);
    });

    it('keeps the post-click and post-view split out of the breakdown', function (): void {
        $record = (new LinkedInMapper)->insight(
            linkedInInsight('insights_campaign', 'urn:li:sponsoredCampaignGroup:603407684'),
            AdEntityType::Campaign,
            'SAR',
        );

        $types = array_map(static fn ($c): string => $c->rawActionType, $record->conversionBreakdown);

        // They are the two halves of `externalWebsiteConversions`, so a row each would
        // count the same conversion twice.
        expect($types)->not->toContain('externalWebsitePostClickConversions')
            ->and($record->metadata)->toHaveKey('external_website_post_click_conversions');
    });

    it('maps the conversion columns it knows', function (): void {
        $mapper = new LinkedInMapper;

        expect($mapper->conversionType('oneClickLeads'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('qualifiedLeads'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('talentLeads'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('somethingNew'))->toBe(ConversionActionType::Other);
    });

    it('carries a metric it cannot name into metadata', function (): void {
        $record = (new LinkedInMapper)->insight(
            [
                'pivotValues' => ['urn:li:sponsoredCampaign:1'],
                'dateRange' => ['start' => ['year' => 2026, 'month' => 9, 'day' => 7]],
                'impressions' => 10,
                'somethingLinkedInAddedLater' => 3,
            ],
            AdEntityType::AdGroup,
            'SAR',
        );

        expect($record->metadata['unmapped_metrics'] ?? [])->toHaveKey('somethingLinkedInAddedLater');
    });

    it('files a hiring objective as other rather than as a sales lead', function (): void {
        $mapper = new LinkedInMapper;

        /*
         * A job applicant is not a sales lead. Filing it under Leads would put hiring
         * spend in a conversion chart a client reads as pipeline.
         */
        expect($mapper->objective('JOB_APPLICANTS'))->toBe(CampaignObjective::Other)
            ->and($mapper->objective('LEAD_GENERATION'))->toBe(CampaignObjective::Leads)
            ->and($mapper->objective('BRAND_AWARENESS'))->toBe(CampaignObjective::Awareness)
            ->and($mapper->objective('WEBSITE_VISITS'))->toBe(CampaignObjective::Traffic)
            ->and($mapper->objective('WEBSITE_CONVERSIONS'))->toBe(CampaignObjective::Sales)
            ->and($mapper->objective('VIDEO_VIEWS'))->toBe(CampaignObjective::VideoViews);
    });
});
