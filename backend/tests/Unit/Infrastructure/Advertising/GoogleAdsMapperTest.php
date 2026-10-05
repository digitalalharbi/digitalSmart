<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\GoogleAdsMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| Google Ads normalisation
|--------------------------------------------------------------------------
|
| Google's response is protobuf JSON: lowerCamelCase keys, enums as name strings,
| and int64 values as *strings*. Money is micros, not minor units. Field names
| were read from the v22 resource and metrics definitions rather than from
| memory, which caught one outright: there is no `metrics.video_views` — the
| metric is `videoTrueviewViews`, and completions exist only as a rate.
|
*/

function gadsFixtures(string $today = '2026-10-05'): SandboxFixtures
{
    return new SandboxFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable($today));
}

/**
 * The first fixture row satisfying a predicate.
 *
 * @param  callable(array<string, mixed>): bool  $matches
 * @return array<string, mixed>
 */
function gadsInsightRow(string $file, callable $matches): array
{
    foreach (gadsFixtures()->rows(Provider::GoogleAds, $file) as $row) {
        if ($matches($row)) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row matched.");
}

/** @return array<string, mixed> */
function gadsRow(string $file, string $id, string $resource = 'campaign'): array
{
    foreach (gadsFixtures()->rows(Provider::GoogleAds, $file) as $row) {
        $object = $row[$resource] ?? null;

        if (is_array($object) && ($object['id'] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row with {$resource}.id = {$id}.");
}

describe('campaigns', function (): void {
    it('converts a micros budget into minor units', function (): void {
        $campaign = (new GoogleAdsMapper)->campaign(gadsRow('campaigns', '20110001'));

        // 180,000,000 micros is 180.00 SAR. Reading micros as minor units would
        // report a budget 10,000 times too large.
        expect($campaign->budgetMode)->toBe(BudgetMode::Daily)
            ->and($campaign->budgetMinor)->toBe(18_000)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('reads a custom-period total as a lifetime budget', function (): void {
        $campaign = (new GoogleAdsMapper)->campaign(gadsRow('campaigns', '20110003'));

        expect($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->budgetMinor)->toBe(900_000);
    });

    it('records that a budget is shared rather than hiding it', function (): void {
        $campaign = (new GoogleAdsMapper)->campaign(gadsRow('campaigns', '20110002'));

        // A shared budget still sets this campaign's spend, but pacing has to
        // know it is not this campaign's alone.
        expect($campaign->metadata['budget_explicitly_shared'] ?? null)->toBeTrue()
            ->and($campaign->budgetMinor)->toBe(42_000);
    });

    it('uses primaryStatus to tell delivering from merely enabled', function (): void {
        $mapper = new GoogleAdsMapper;

        $eligible = $mapper->campaign(gadsRow('campaigns', '20110001'));
        $limited = $mapper->campaign(gadsRow('campaigns', '20110002'));
        $broken = $mapper->campaign(gadsRow('campaigns', '20110004'));

        /*
         * All three are ENABLED. Without primaryStatus they would look
         * identical, and two of them are not delivering properly.
         */
        expect($eligible->status)->toBe(EntityStatus::Active)
            ->and($limited->status)->toBe(EntityStatus::NotDelivering)
            ->and($limited->rawStatus)->toBe('ENABLED')
            ->and($limited->rawEffectiveStatus)->toBe('LIMITED')
            ->and($broken->status)->toBe(EntityStatus::NotDelivering)
            ->and($broken->rawEffectiveStatus)->toBe('MISCONFIGURED');
    });

    it('keeps the reason a campaign is held back', function (): void {
        $campaign = (new GoogleAdsMapper)->campaign(gadsRow('campaigns', '20110002'));

        expect($campaign->metadata['primary_status_reasons'] ?? null)->toBe(['BUDGET_CONSTRAINED']);
    });

    it('infers an objective and records what it inferred it from', function (): void {
        $mapper = new GoogleAdsMapper;

        /*
         * Google has no objective field. The inference is approximate by
         * necessity, so the channel/sub-type pair it came from is stored
         * alongside — an approximation presented as the platform's own word
         * would be the dishonest version.
         */
        expect($mapper->campaign(gadsRow('campaigns', '20110002'))->objective)
            ->toBe(CampaignObjective::Sales)
            ->and($mapper->campaign(gadsRow('campaigns', '20110002'))->rawObjective)
            ->toBe('PERFORMANCE_MAX')
            ->and($mapper->campaign(gadsRow('campaigns', '20110001'))->objective)
            ->toBe(CampaignObjective::Traffic)
            // A sub-type can override the channel's default reading.
            ->and($mapper->campaign(gadsRow('campaigns', '20110003'))->objective)
            ->toBe(CampaignObjective::Reach)
            ->and($mapper->campaign(gadsRow('campaigns', '20110003'))->rawObjective)
            ->toBe('VIDEO/VIDEO_REACH_TARGET_FREQUENCY');
    });

    it('does not invent a campaign spend cap', function (): void {
        $campaign = (new GoogleAdsMapper)->campaign(gadsRow('campaigns', '20110001'));

        // Google has no campaign-level cap; account limits are a different
        // resource and must not be reported as if they were one.
        expect($campaign->spendCapMinor)->toBeNull();
    });
});

describe('ad groups', function (): void {
    it('reads the bid actually in force, in minor units', function (): void {
        $adGroup = (new GoogleAdsMapper)->adGroup(gadsRow('ad_groups', '30110001', 'adGroup'));

        // 2,400,000 micros is 2.40 SAR. `effectiveCpcBidMicros` is what applies,
        // which is the number an operator is asking about.
        expect($adGroup->bidAmountMinor)->toBe(240)
            ->and($adGroup->campaignExternalId)->toBe('20110001');
    });

    it('says budgets are inherited, because in Google they are', function (): void {
        $adGroup = (new GoogleAdsMapper)->adGroup(gadsRow('ad_groups', '30110003', 'adGroup'));

        expect($adGroup->budgetMode)->toBe(BudgetMode::Inherited)
            ->and($adGroup->budgetMinor)->toBeNull();
    });

    it('reports no targeting rather than summarising criteria it never read', function (): void {
        $adGroup = (new GoogleAdsMapper)->adGroup(gadsRow('ad_groups', '30110001', 'adGroup'));

        // Targeting is a set of separate criterion resources. An empty array
        // says we have not read them; a summary would misrepresent reach.
        expect($adGroup->targeting)->toBe([]);
    });

    it('derives the campaign id from the resource name', function (): void {
        $adGroup = (new GoogleAdsMapper)->adGroup(gadsRow('ad_groups', '30110004', 'adGroup'));

        expect($adGroup->campaignExternalId)->toBe('20110003');
    });
});

describe('ads', function (): void {
    it('treats a disapproved ad as rejected and says why', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'ads')[4];
        $ad = (new GoogleAdsMapper)->ad($row);

        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->rawStatus)->toBe('ENABLED')
            ->and($ad->rawEffectiveStatus)->toBe('DISAPPROVED')
            ->and($ad->reviewFeedback)->toContain('MISLEADING_CLAIMS_IN_AD_TEXT');
    });

    it('treats a policy-limited ad as not delivering, not as healthy', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'ads')[1];
        $ad = (new GoogleAdsMapper)->ad($row);

        // APPROVED_LIMITED serves, but not everywhere. Calling it active would
        // hide a real delivery problem behind a green label.
        expect($ad->status)->toBe(EntityStatus::NotDelivering)
            ->and($ad->reviewFeedback)->toContain('TRADEMARKS_IN_AD_TEXT');
    });

    it('parses the composite adGroupAd resource name', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'ads')[0];
        $ad = (new GoogleAdsMapper)->ad($row);

        expect($ad->adGroupExternalId)->toBe('30110001')
            ->and($ad->campaignExternalId)->toBe('20110001')
            ->and($ad->externalId)->toBe('40110001');
    });

    it('names an unnamed ad after its first headline', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'ads')[0];
        $ad = (new GoogleAdsMapper)->ad($row);

        // Google ads often have no name. A blank row in a report is useless, and
        // the headline is what the advertiser would recognise.
        expect($ad->name)->toBe('الحمراء للتجزئة');
    });
});

describe('creatives', function (): void {
    it('reads a responsive search ad with all of its assets', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'creatives')[0];
        $creative = (new GoogleAdsMapper)->creative($row);

        /*
         * Google decides which headlines serve, so keeping only the first would
         * discard most of the creative. Three headlines and two descriptions
         * means five assets.
         */
        expect($creative->format)->toBe(CreativeFormat::ResponsiveSearch)
            ->and($creative->assets)->toHaveCount(5)
            ->and($creative->title)->toBe('الحمراء للتجزئة')
            ->and($creative->destinationUrl)->toBe('https://sandbox.digitalsmart.invalid/alhambra');
    });

    it('does not invent a call to action Google generates itself', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'creatives')[0];

        expect((new GoogleAdsMapper)->creative($row)->callToAction)->toBeNull();
    });

    it('recognises responsive display and video formats', function (): void {
        $rows = gadsFixtures()->rows(Provider::GoogleAds, 'creatives');
        $mapper = new GoogleAdsMapper;

        expect($mapper->creative($rows[2])->format)->toBe(CreativeFormat::ResponsiveDisplay)
            ->and($mapper->creative($rows[3])->format)->toBe(CreativeFormat::Video);
    });
});

describe('insights', function (): void {
    it('converts cost micros into minor units', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        $expected = (int) round((int) $row['metrics']['costMicros'] / 10_000);

        expect($insight->spendMinor)->toBe($expected)
            ->and($insight->currency)->toBe('SAR');
    });

    it('reads the date from where Google puts it', function (): void {
        $row = gadsFixtures('2026-10-05')->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // `segments.date`, nested, exactly as a live row carries it.
        expect($row['segments']['date'] ?? null)->toBe('2026-09-21')
            ->and($insight->date->format('Y-m-d'))->toBe('2026-09-21');
    });

    it('keeps conversions fractional, because Google reports them that way', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_campaign')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Campaign, 'SAR');

        // Rounding to an integer here would lose attributed fractions and make
        // CPA disagree with Google's own interface.
        expect($insight->conversions)->toBeFloat()
            ->and($insight->conversions)->toBe((float) $row['metrics']['conversions']);
    });

    it('reports no reach, because Google publishes none at this level', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * There is no general deduplicated reach in Google Ads. Null says so.
         * Approximating it from impressions would invent a number the platform
         * refuses to give, and frequency would follow it.
         */
        expect($insight->reach)->toBeNull();
    });

    it('reports no link clicks separately from clicks', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // Copying clicks into link clicks would make Google's two CTRs identical
        // for a reason that is ours, not the platform's.
        expect($insight->clicks)->toBeGreaterThan(0)
            ->and($insight->linkClicks)->toBeNull();
    });

    it('derives video completions from the rate, and keeps the rate', function (): void {
        $video = gadsInsightRow(
            'insights_ad',
            static fn (array $row): bool => isset($row['metrics']['videoQuartileP100Rate']),
        );
        $insight = (new GoogleAdsMapper)->insight($video, AdEntityType::Ad, 'SAR');

        $impressions = (int) $video['metrics']['impressions'];
        $rate = (float) $video['metrics']['videoQuartileP100Rate'];

        /*
         * Google reports quartile completion as a percentage of impressions, not
         * a count. The product is exact rather than an estimate, but it is still
         * a derivation, so the original rate is preserved in metadata.
         */
        expect($insight->videoCompletions)->toBe((int) round($impressions * $rate / 100))
            ->and($insight->metadata['video_quartile_p100_rate'] ?? null)->toBe($rate)
            // v22 has no `videoViews`; the metric is `videoTrueviewViews`.
            ->and($insight->videoViews)->toBe((int) $video['metrics']['videoTrueviewViews']);
    });

    it('leaves video metrics null on a non-video ad', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        expect($insight->videoViews)->toBeNull()
            ->and($insight->videoCompletions)->toBeNull();
    });

    it('returns no conversion breakdown when the query did not segment by action', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];
        $insight = (new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // One conversion row per action would have to be invented. An empty
        // breakdown says the data was not requested, which is the truth.
        expect($insight->conversionBreakdown)->toBe([]);
    });

    it('reads the account timezone, so a day means what Google meant', function (): void {
        $row = gadsFixtures()->rows(Provider::GoogleAds, 'insights_ad')[0];

        expect((new GoogleAdsMapper)->insight($row, AdEntityType::Ad, 'SAR')->accountTimezone)
            ->toBe('Asia/Riyadh');
    });
});

describe('conversion categories', function (): void {
    it('maps Google categories onto our vocabulary', function (): void {
        $mapper = new GoogleAdsMapper;

        expect($mapper->conversionType('PURCHASE'))->toBe(ConversionActionType::Purchase)
            ->and($mapper->conversionType('ADD_TO_CART'))->toBe(ConversionActionType::AddToCart)
            ->and($mapper->conversionType('BEGIN_CHECKOUT'))->toBe(ConversionActionType::InitiateCheckout)
            // Google has several distinct lead categories; all are leads to us.
            ->and($mapper->conversionType('SUBMIT_LEAD_FORM'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('PHONE_CALL_LEAD'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('QUALIFIED_LEAD'))->toBe(ConversionActionType::Lead)
            ->and($mapper->conversionType('STORE_VISIT'))->toBe(ConversionActionType::StoreVisit)
            // DEFAULT is "an advertiser-defined conversion", which is custom.
            ->and($mapper->conversionType('DEFAULT'))->toBe(ConversionActionType::Custom)
            ->and($mapper->conversionType('SOMETHING_NEW'))->toBe(ConversionActionType::Other);
    });
});

describe('unknown values', function (): void {
    it('does not guess at a status or an objective it has not seen', function (): void {
        $mapper = new GoogleAdsMapper;

        expect($mapper->status('SOME_NEW_STATUS', null))->toBe(EntityStatus::Unknown)
            ->and($mapper->status(null, null))->toBe(EntityStatus::Unknown)
            ->and($mapper->objective('SOME_NEW_CHANNEL', null))->toBe(CampaignObjective::Other);
    });

    it('keeps an enabled campaign active when primaryStatus is unrecognised', function (): void {
        // A new primaryStatus value must not silently stop a working campaign
        // from appearing in a report.
        expect((new GoogleAdsMapper)->status('ENABLED', 'SOME_NEW_PRIMARY_STATUS'))
            ->toBe(EntityStatus::Active);
    });
});
