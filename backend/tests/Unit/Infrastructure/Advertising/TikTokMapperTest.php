<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\TikTokMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| TikTok normalisation
|--------------------------------------------------------------------------
|
| A third set of conventions after Meta's and Google's: budgets are plain floats
| in the account currency, delivery state is split across operation_status and
| secondary_status, reporting rows are {dimensions, metrics} with every value a
| string, and an unavailable metric arrives as the literal "-".
|
| This mapper is deliberately narrow. TikTok's metric-name reference could not be
| read (the page is only reachable from inside its own SPA and opens in a blocked
| tab), so only names confirmed from the endpoint documentation are normalised.
| The rest are carried into metadata untouched. Guessing one would put a
| plausible number in the wrong column, which is worse than an empty one.
|
*/

function ttFixtures(string $today = '2026-10-05'): SandboxFixtures
{
    return new SandboxFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable($today));
}

/** @return array<string, mixed> */
function ttRow(string $file, string $key, string $id): array
{
    foreach (ttFixtures()->rows(Provider::TikTok, $file) as $row) {
        if (($row[$key] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row with {$key} = {$id}.");
}

describe('campaigns', function (): void {
    it('reads a budget float in the account currency', function (): void {
        $campaign = (new TikTokMapper)->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000001'), 'SAR');

        /*
         * 1200.0 is 1,200.00 SAR. Not minor-unit strings like Meta's budgets and
         * not micros like Google's — a third convention, and reading it as
         * either of the others is off by a factor of 100 or 10,000.
         */
        expect($campaign->budgetMode)->toBe(BudgetMode::Daily)
            ->and($campaign->budgetMinor)->toBe(120_000)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('reads a lifetime budget', function (): void {
        $campaign = (new TikTokMapper)->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000002'), 'SAR');

        expect($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->budgetMinor)->toBe(4_500_000);
    });

    it('treats an infinite campaign budget as unlimited, not as zero', function (): void {
        $campaign = (new TikTokMapper)->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000003'), 'SAR');

        // BUDGET_MODE_INFINITE means the ad groups carry the budget. Zero would
        // read as "no money allocated", which is a different and wrong story.
        expect($campaign->budgetMode)->toBe(BudgetMode::Unlimited)
            ->and($campaign->budgetMinor)->toBeNull();
    });

    it('uses secondary_status to tell enabled from delivering', function (): void {
        $mapper = new TikTokMapper;

        $live = $mapper->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000001'), 'SAR');
        $overBudget = $mapper->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000002'), 'SAR');
        $inReview = $mapper->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000004'), 'SAR');
        $rejected = $mapper->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000005'), 'SAR');

        /*
         * All four have operation_status ENABLE. Only one of them is actually
         * spending; the other three are over budget, under review and
         * disapproved respectively.
         */
        expect($live->status)->toBe(EntityStatus::Active)
            ->and($overBudget->status)->toBe(EntityStatus::NotDelivering)
            ->and($overBudget->rawStatus)->toBe('ENABLE')
            ->and($overBudget->rawEffectiveStatus)->toBe('CAMPAIGN_STATUS_BUDGET_EXCEED')
            ->and($inReview->status)->toBe(EntityStatus::InReview)
            ->and($rejected->status)->toBe(EntityStatus::Rejected);
    });

    it('maps the objective enum, deprecated values included', function (): void {
        $mapper = new TikTokMapper;

        expect($mapper->objective('WEB_CONVERSIONS'))->toBe(CampaignObjective::Sales)
            ->and($mapper->objective('PRODUCT_SALES'))->toBe(CampaignObjective::Sales)
            // Deprecated but still on live campaigns created before the rename.
            ->and($mapper->objective('CONVERSIONS'))->toBe(CampaignObjective::Sales)
            ->and($mapper->objective('APP_INSTALL'))->toBe(CampaignObjective::AppPromotion)
            ->and($mapper->objective('APP_PROMOTION'))->toBe(CampaignObjective::AppPromotion)
            ->and($mapper->objective('REACH'))->toBe(CampaignObjective::Reach)
            ->and($mapper->objective('RF_REACH'))->toBe(CampaignObjective::Reach)
            ->and($mapper->objective('LEAD_GENERATION'))->toBe(CampaignObjective::Leads)
            ->and($mapper->objective('VIDEO_VIEWS'))->toBe(CampaignObjective::VideoViews)
            ->and($mapper->objective('SOMETHING_NEW'))->toBe(CampaignObjective::Other);
    });

    it('records that campaign budget optimisation is on', function (): void {
        $campaign = (new TikTokMapper)->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000001'), 'SAR');

        // With CBO the campaign, not its ad groups, decides where the money
        // goes — which changes how pacing has to read the hierarchy.
        expect($campaign->metadata['budget_optimize_on'] ?? null)->toBeTrue();
    });

    it('leaves campaign dates null, because TikTok schedules per ad group', function (): void {
        $campaign = (new TikTokMapper)->campaign(ttRow('campaigns', 'campaign_id', '1810000000000000001'), 'SAR');

        // Copying the first ad group's dates up would invent a campaign schedule
        // that does not exist in the platform.
        expect($campaign->startsAt)->toBeNull()
            ->and($campaign->endsAt)->toBeNull()
            ->and($campaign->providerCreatedAt?->format('Y-m-d'))->toBe('2026-08-12');
    });
});

describe('ad groups', function (): void {
    it('reads the conversion bid as minor units', function (): void {
        $adGroup = (new TikTokMapper)->adGroup(ttRow('ad_groups', 'adgroup_id', '1820000000000000001'), 'SAR');

        expect($adGroup->bidAmountMinor)->toBe(4_200)
            ->and($adGroup->campaignExternalId)->toBe('1810000000000000001')
            ->and($adGroup->optimizationGoal)->toBe('CONVERT')
            ->and($adGroup->billingEvent)->toBe('OCPM');
    });

    it('collects the targeting fields TikTok returns as siblings', function (): void {
        $adGroup = (new TikTokMapper)->adGroup(ttRow('ad_groups', 'adgroup_id', '1820000000000000001'), 'SAR');

        // TikTok does not nest targeting in one object the way Meta does, so the
        // fields present are gathered without being reshaped.
        expect($adGroup->targeting['location_ids'] ?? null)->toBe(['6252001'])
            ->and($adGroup->targeting['age_groups'] ?? null)->toContain('AGE_25_34')
            ->and($adGroup->targeting['gender'] ?? null)->toBe('GENDER_UNLIMITED');
    });

    it('reads its own schedule window', function (): void {
        $adGroup = (new TikTokMapper)->adGroup(ttRow('ad_groups', 'adgroup_id', '1820000000000000003'), 'SAR');

        expect($adGroup->startsAt?->format('Y-m-d'))->toBe('2026-07-03')
            ->and($adGroup->endsAt?->format('Y-m-d'))->toBe('2026-12-31');
    });
});

describe('ads and creatives', function (): void {
    it('says why an ad was rejected', function (): void {
        $ad = (new TikTokMapper)->ad(ttRow('ads', 'ad_id', '1830000000000000006'));

        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->reviewFeedback)->toContain('misleading claims');
    });

    it('reuses the ad id as the creative id, because TikTok has no creative resource', function (): void {
        $ad = (new TikTokMapper)->ad(ttRow('ads', 'ad_id', '1830000000000000001'));

        expect($ad->creativeExternalId)->toBe('1830000000000000001');
    });

    it('reads a video creative with its assets and Arabic copy', function (): void {
        $creative = (new TikTokMapper)->creative(ttRow('creatives', 'ad_id', '1830000000000000001'));

        expect($creative->format)->toBe(CreativeFormat::Video)
            ->and($creative->body)->toBe('عروض رمضان من الحمراء — توصيل مجاني')
            ->and($creative->callToAction)->toBe('SHOP_NOW')
            // Video first, then its image.
            ->and($creative->assets)->toHaveCount(2)
            ->and($creative->assets[0]->kind)->toBe('video');
    });

    it('does not invent a headline TikTok has no field for', function (): void {
        $creative = (new TikTokMapper)->creative(ttRow('creatives', 'ad_id', '1830000000000000001'));

        // `ad_text` is the whole caption; there is no separate headline to split
        // out, and splitting one off would be editorialising the creative.
        expect($creative->title)->toBeNull();
    });

    it('recognises a carousel by its image list', function (): void {
        $creative = (new TikTokMapper)->creative(ttRow('creatives', 'ad_id', '1830000000000000002'));

        expect($creative->format)->toBe(CreativeFormat::Carousel)
            ->and($creative->assets)->toHaveCount(3);
    });
});

describe('insights', function (): void {
    it('reads the dimensions/metrics split and the date inside dimensions', function (): void {
        $row = ttFixtures('2026-10-05')->rows(Provider::TikTok, 'insights_ad')[0];
        $insight = (new TikTokMapper)->insight($row, AdEntityType::Ad, 'SAR');

        expect($insight->externalEntityId)->toBe('1830000000000000001')
            ->and($insight->date->format('Y-m-d'))->toBe('2026-09-21')
            ->and($insight->accountTimezone)->toBe('Asia/Riyadh');
    });

    it('reads string metrics and converts spend to minor units', function (): void {
        $row = ttFixtures()->rows(Provider::TikTok, 'insights_ad')[0];
        $insight = (new TikTokMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // Every TikTok metric arrives as a string, spend included.
        expect($row['metrics']['spend'])->toBeString()
            ->and($insight->spendMinor)->toBe(
                (int) round((float) $row['metrics']['spend'] * 100),
            )
            ->and($insight->impressions)->toBe((int) $row['metrics']['impressions']);
    });

    it('turns TikTok’s "-" sentinel into null, never into zero', function (): void {
        $unavailable = null;

        foreach (ttFixtures()->rows(Provider::TikTok, 'insights_ad') as $row) {
            if (($row['metrics']['reach'] ?? null) === '-') {
                $unavailable = $row;
                break;
            }
        }

        expect($unavailable)->not->toBeNull();

        /** @var array<string, mixed> $unavailable */
        $insight = (new TikTokMapper)->insight($unavailable, AdEntityType::Ad, 'SAR');

        /*
         * "-" means TikTok could not produce the figure — a UV metric under
         * maintenance, or a cost metric on a row with no id dimension. Casting
         * it gives 0, which reads as "nobody was reached".
         */
        expect($insight->reach)->toBeNull()
            ->and($insight->impressions)->toBeGreaterThan(0);
    });

    it('leaves unverified metrics null rather than guessing a name', function (): void {
        $row = ttFixtures()->rows(Provider::TikTok, 'insights_ad')[0];
        $insight = (new TikTokMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * TikTok's metric-name reference could not be read. Rather than guess at
         * click, video and revenue metric names, they stay null — a chart can
         * label a gap, but it cannot detect a plausible figure in the wrong
         * column.
         */
        expect($insight->clicks)->toBeNull()
            ->and($insight->linkClicks)->toBeNull()
            ->and($insight->videoViews)->toBeNull()
            ->and($insight->revenueMinor)->toBeNull()
            // What *was* verified still works.
            ->and($insight->conversions)->toBeFloat();
    });

    it('keeps the cost breakdown TikTok reports alongside spend', function (): void {
        $row = ttFixtures()->rows(Provider::TikTok, 'insights_ad')[0];
        $insight = (new TikTokMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * `spend` is what the campaign consumed; `cash_spend` and `voucher_spend`
         * split it by how it was paid. A client reconciling an invoice needs the
         * split, so it is kept rather than collapsed into one figure.
         */
        expect($insight->metadata['billed_cost'] ?? null)->not->toBeNull()
            ->and($insight->metadata['cash_spend'] ?? null)->not->toBeNull()
            ->and($insight->metadata['voucher_spend'] ?? null)->toBe('0.00');
    });

    it('partitions metrics into verified and unmapped, keeping both', function (): void {
        $row = ttFixtures()->rows(Provider::TikTok, 'insights_ad')[0];
        // A metric name this mapper has not verified. Deliberately not a real
        // TikTok name: inventing one in a fixture would be the very thing the
        // mapper refuses to do.
        $row['metrics']['unverified_example_metric'] = '123';

        $insight = (new TikTokMapper)->insight($row, AdEntityType::Ad, 'SAR');
        $unmapped = $insight->metadata['unmapped_metrics'] ?? [];

        /*
         * Anything the mapper has not confirmed is carried through untouched, so
         * a later verification pass can map it without re-syncing — and so
         * nothing was quietly discarded in the meantime.
         */
        expect($unmapped)->toHaveKey('unverified_example_metric')
            // Verified metrics are mapped, so they must not also appear here.
            ->and($unmapped)->not->toHaveKey('spend')
            ->and($unmapped)->not->toHaveKey('impressions');
    });

    it('takes the entity id from the dimension matching the level', function (): void {
        $mapper = new TikTokMapper;
        $row = ttFixtures()->rows(Provider::TikTok, 'insights_ad')[0];

        expect($mapper->insight($row, AdEntityType::AdGroup, 'SAR')->externalEntityId)
            ->toBe('1820000000000000001')
            ->and($mapper->insight($row, AdEntityType::Campaign, 'SAR')->externalEntityId)
            ->toBe('1810000000000000001')
            ->and($mapper->insight($row, AdEntityType::Account, 'SAR')->externalEntityId)
            ->toBe('7100000000000000001');
    });
});

describe('unknown values', function (): void {
    it('does not guess a status it has not seen', function (): void {
        $mapper = new TikTokMapper;

        expect($mapper->status('SOMETHING_NEW', null))->toBe(EntityStatus::Unknown)
            ->and($mapper->status(null, null))->toBe(EntityStatus::Unknown)
            // An unrecognised secondary status must not stop a live campaign
            // appearing in a report.
            ->and($mapper->status('ENABLE', 'CAMPAIGN_STATUS_SOMETHING_NEW'))->toBe(EntityStatus::Active)
            ->and($mapper->budgetMode('BUDGET_MODE_NEW'))->toBe(BudgetMode::Unknown)
            ->and($mapper->format('NEW_AD_FORMAT'))->toBe(CreativeFormat::Unknown);
    });
});
