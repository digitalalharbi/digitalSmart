<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\SnapchatMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| Snapchat normalisation
|--------------------------------------------------------------------------
|
| A fourth set of conventions. Money is micro-currency like Google's, every list item
| is wrapped twice because a batch can partly succeed, a click is a "swipe", reach is
| "uniques", and `status` is only ACTIVE or PAUSED — why something is not delivering
| lives in a `delivery_status` array.
|
*/

function snapFixtures(string $today = '2026-10-06'): SandboxFixtures
{
    return new SandboxFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable($today));
}

/**
 * The first stats row satisfying a predicate.
 *
 * @param  callable(array<string, mixed>): bool  $matches
 * @return array<string, mixed>
 */
function snapStatsRow(callable $matches): array
{
    foreach (snapFixtures()->rows(Provider::Snapchat, 'insights_ad') as $row) {
        if ($matches($row)) {
            return $row;
        }
    }

    throw new RuntimeException('No Snapchat stats row matched.');
}

/** @return array<string, mixed> */
function snapRow(string $file, string $key, string $id): array
{
    foreach (snapFixtures()->rows(Provider::Snapchat, $file) as $row) {
        $entity = $row[$key] ?? null;

        if (is_array($entity) && ($entity['id'] ?? null) === $id) {
            return $entity;
        }
    }

    throw new RuntimeException("No {$file} row with {$key}.id = {$id}.");
}

describe('campaigns', function (): void {
    it('converts a micros daily budget into minor units', function (): void {
        $campaign = (new SnapchatMapper)->campaign(
            snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000001'),
            'SAR',
        );

        // 900,000,000 micros is 900.00 SAR.
        expect($campaign->budgetMode)->toBe(BudgetMode::Daily)
            ->and($campaign->budgetMinor)->toBe(90_000)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('treats a lifetime spend cap as both a cap and the budget when it stands alone', function (): void {
        $campaign = (new SnapchatMapper)->campaign(
            snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000002'),
            'SAR',
        );

        /*
         * Snapchat's lifetime field is a spend *cap*, not a budget: it limits total
         * spend while a daily budget paces delivery. With no daily budget it is the
         * only constraint, so it is reported as both.
         */
        expect($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->budgetMinor)->toBe(4_000_000)
            ->and($campaign->spendCapMinor)->toBe(4_000_000);
    });

    it('uses delivery_status to tell active from delivering', function (): void {
        $mapper = new SnapchatMapper;

        $live = $mapper->campaign(snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000001'), 'SAR');
        $capped = $mapper->campaign(snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000002'), 'SAR');
        $empty = $mapper->campaign(snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000004'), 'SAR');

        /*
         * All three are ACTIVE. One is spending; one has hit its spend cap; one has no
         * eligible ad squads. `status` alone cannot tell them apart, which is exactly
         * what a media buyer needs to know.
         */
        expect($live->status)->toBe(EntityStatus::Active)
            ->and($capped->status)->toBe(EntityStatus::NotDelivering)
            ->and($capped->rawStatus)->toBe('ACTIVE')
            ->and($capped->rawEffectiveStatus)->toBe('PAUSED_BY_SPEND_CAP')
            ->and($empty->status)->toBe(EntityStatus::NotDelivering);
    });

    it('maps the objective enum', function (): void {
        $mapper = new SnapchatMapper;

        expect($mapper->objective('WEB_CONVERSION'))->toBe(CampaignObjective::Sales)
            ->and($mapper->objective('CATALOG_SALES'))->toBe(CampaignObjective::Sales)
            ->and($mapper->objective('BRAND_AWARENESS'))->toBe(CampaignObjective::Awareness)
            ->and($mapper->objective('VIDEO_VIEW'))->toBe(CampaignObjective::VideoViews)
            ->and($mapper->objective('LEAD_GENERATION'))->toBe(CampaignObjective::Leads)
            ->and($mapper->objective('APP_INSTALL'))->toBe(CampaignObjective::AppPromotion)
            ->and($mapper->objective('PROMOTE_PLACES'))->toBe(CampaignObjective::StoreTraffic)
            // A value Snap adds next year must not silently become something else.
            ->and($mapper->objective('SOMETHING_NEW'))->toBe(CampaignObjective::Other);
    });

    it('leaves bid strategy to the ad squad, where Snapchat puts it', function (): void {
        $campaign = (new SnapchatMapper)->campaign(
            snapRow('campaigns', 'campaign', '11111111-aaaa-4bbb-8ccc-000000000001'),
            'SAR',
        );

        expect($campaign->bidStrategy)->toBeNull()
            ->and($campaign->buyingType)->toBe('AUCTION');
    });
});

describe('ad squads', function (): void {
    it('reads a max bid in micros', function (): void {
        $squad = (new SnapchatMapper)->adGroup(
            snapRow('ad_groups', 'adsquad', '22222222-aaaa-4bbb-8ccc-000000000002'),
            'SAR',
        );

        // 3,400,000 micros is 3.40 SAR.
        expect($squad->bidAmountMinor)->toBe(340)
            ->and($squad->metadata['bid_strategy'] ?? null)->toBe('LOWEST_COST_WITH_MAX_BID');
    });

    it('treats an auto-bid squad as having no bid rather than a zero one', function (): void {
        $squad = (new SnapchatMapper)->adGroup(
            snapRow('ad_groups', 'adsquad', '22222222-aaaa-4bbb-8ccc-000000000001'),
            'SAR',
        );

        // A bid of zero would be a claim about the campaign; absence is the truth.
        expect($squad->bidAmountMinor)->toBeNull()
            ->and($squad->metadata['bid_strategy'] ?? null)->toBe('AUTO_BID');
    });

    it('reads a budget set on the squad rather than the campaign', function (): void {
        $squad = (new SnapchatMapper)->adGroup(
            snapRow('ad_groups', 'adsquad', '22222222-aaaa-4bbb-8ccc-000000000003'),
            'SAR',
        );

        expect($squad->budgetMode)->toBe(BudgetMode::Daily)
            ->and($squad->budgetMinor)->toBe(60_000)
            ->and($squad->campaignExternalId)->toBe('11111111-aaaa-4bbb-8ccc-000000000002');
    });

    it('keeps targeting as Snapchat shaped it', function (): void {
        $squad = (new SnapchatMapper)->adGroup(
            snapRow('ad_groups', 'adsquad', '22222222-aaaa-4bbb-8ccc-000000000001'),
            'SAR',
        );

        expect($squad->targeting['geos'][0]['country_code'] ?? null)->toBe('sa')
            ->and($squad->targeting['demographics'][0]['min_age'] ?? null)->toBe(18)
            // The attribution window matters for reading the conversion figures.
            ->and($squad->metadata['conversion_window']['swipe_up_attribution_window'] ?? null)
            ->toBe('SEVEN_DAY');
    });

    it('records that Snapchat bills only on impressions', function (): void {
        $squad = (new SnapchatMapper)->adGroup(
            snapRow('ad_groups', 'adsquad', '22222222-aaaa-4bbb-8ccc-000000000001'),
            'SAR',
        );

        // Reported as given rather than normalised away, so a future addition shows up.
        expect($squad->billingEvent)->toBe('IMPRESSION')
            ->and($squad->optimizationGoal)->toBe('PIXEL_PURCHASE');
    });
});

describe('ads', function (): void {
    it('treats a rejected ad as rejected and says why', function (): void {
        $ad = (new SnapchatMapper)->ad(snapRow('ads', 'ad', '33333333-aaaa-4bbb-8ccc-000000000005'));

        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->rawStatus)->toBe('ACTIVE')
            ->and($ad->rawEffectiveStatus)->toBe('REJECTED')
            ->and($ad->reviewFeedback)->toContain('employment guarantee');
    });

    it('carries no campaign id, because Snapchat’s ad does not', function (): void {
        $ad = (new SnapchatMapper)->ad(snapRow('ads', 'ad', '33333333-aaaa-4bbb-8ccc-000000000001'));

        /*
         * The hierarchy is reached through the ad squad. Inventing a campaign id here
         * would be inventing a field; the store resolves it from the ad squad it has
         * already synced.
         */
        expect($ad->campaignExternalId)->toBe('')
            ->and($ad->adGroupExternalId)->toBe('22222222-aaaa-4bbb-8ccc-000000000001')
            ->and($ad->creativeExternalId)->toBe('44444444-aaaa-4bbb-8ccc-000000000001');
    });

    it('treats a pending review as in review, not as active', function (): void {
        $mapper = new SnapchatMapper;

        expect($mapper->adStatus('ACTIVE', 'PENDING', ['ACTIVE']))->toBe(EntityStatus::InReview)
            ->and($mapper->adStatus('ACTIVE', 'APPROVED', ['ACTIVE']))->toBe(EntityStatus::Active)
            ->and($mapper->adStatus('PAUSED', 'APPROVED', ['PAUSED']))->toBe(EntityStatus::Paused);
    });
});

describe('creatives', function (): void {
    it('reads a web-view creative with its destination and Arabic headline', function (): void {
        $creative = (new SnapchatMapper)->creative(
            snapRow('creatives', 'creative', '44444444-aaaa-4bbb-8ccc-000000000001'),
        );

        expect($creative->title)->toBe('عروض رمضان من الحمراء')
            ->and($creative->callToAction)->toBe('SHOP_NOW')
            ->and($creative->destinationUrl)->toContain('/alhambra/ramadan')
            ->and($creative->metadata['brand_name'] ?? null)->toBe('Alhambra');
    });

    it('does not invent body copy Snapchat has no field for', function (): void {
        $creative = (new SnapchatMapper)->creative(
            snapRow('creatives', 'creative', '44444444-aaaa-4bbb-8ccc-000000000001'),
        );

        // `headline` is the only text field. Splitting one into two would be
        // editorialising the creative.
        expect($creative->body)->toBeNull();
    });

    it('records a top snap as media without guessing whether it is a still or a video', function (): void {
        $creative = (new SnapchatMapper)->creative(
            snapRow('creatives', 'creative', '44444444-aaaa-4bbb-8ccc-000000000001'),
        );

        /*
         * Which it is lives on the media resource, not the creative. Calling it a
         * video would be a guess the interface would then present as fact.
         */
        expect($creative->assets)->toHaveCount(1)
            ->and($creative->assets[0]->kind)->toBe('media')
            ->and($creative->assets[0]->externalId)->toBe('55555555-aaaa-4bbb-8ccc-000000000001');
    });

    it('recognises a collection creative', function (): void {
        $creative = (new SnapchatMapper)->creative(
            snapRow('creatives', 'creative', '44444444-aaaa-4bbb-8ccc-000000000003'),
        );

        expect($creative->format)->toBe(CreativeFormat::Collection);
    });

    it('does not guess a format it has not seen', function (): void {
        expect((new SnapchatMapper)->format('SOME_NEW_TYPE'))->toBe(CreativeFormat::Unknown);
    });
});

describe('stats', function (): void {
    it('reads spend as micro-currency', function (): void {
        $row = snapFixtures()->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * Every money field Snapchat publishes is micros, and `spend` is the one whose
         * name omits the suffix — see the caveat in docs/INTEGRATIONS.md. Reading it as
         * minor units would understate spend ten-thousand-fold.
         */
        expect($insight->spendMinor)->toBe((int) round((int) $row['stats']['spend'] / 10_000));
    });

    it('maps a swipe to a click and reports no separate link click', function (): void {
        $row = snapFixtures()->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // Copying swipes into link clicks would make two different CTRs identical for
        // a reason that is ours rather than the platform's.
        expect($insight->clicks)->toBe((int) $row['stats']['swipes'])
            ->and($insight->linkClicks)->toBeNull();
    });

    it('reads uniques as deduplicated reach', function (): void {
        $row = snapFixtures()->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        expect($insight->reach)->toBe((int) $row['stats']['uniques'])
            ->and($insight->reach)->toBeLessThan($insight->impressions ?? 0);
    });

    it('takes quartile counts as counts, not rates', function (): void {
        $row = snapFixtures()->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * Unlike Google, which reports quartiles as percentages. Deriving a count from
         * these would double-convert and inflate them.
         */
        expect($insight->videoViews)->toBe((int) $row['stats']['quartile_1'])
            ->and($insight->videoCompletions)->toBe((int) $row['stats']['view_completion']);
    });

    it('reads the purchase value as a decimal, not as micros', function (): void {
        $row = snapStatsRow(
            static fn (array $candidate): bool => isset($candidate['stats']['conversion_purchases_value']),
        );
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // The pixel-reported order value arrives as a plain decimal, unlike budgets.
        expect($insight->revenueMinor)
            ->toBe((int) round((float) $row['stats']['conversion_purchases_value'] * 100));
    });

    it('reports no aggregate engagement, because Snapchat publishes none', function (): void {
        $row = snapFixtures()->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * Screen time and attachment views are real figures but not an engagement
         * count. Adding them together would produce a number nobody defined.
         */
        expect($insight->engagements)->toBeNull()
            ->and($insight->metadata['screen_time_millis'] ?? null)->not->toBeNull();
    });

    it('breaks conversions out of the named columns', function (): void {
        $row = snapStatsRow(static fn (array $candidate): bool => isset(
            $candidate['stats']['conversion_purchases'],
            $candidate['stats']['conversion_add_cart'],
        ));
        $breakdown = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR')->conversionBreakdown;
        $byType = [];

        foreach ($breakdown as $record) {
            $byType[$record->rawActionType] = $record;
        }

        /*
         * Snapchat reports conversions as named columns rather than an action array,
         * so each column that carried a figure becomes a row. Only purchases have a
         * value counterpart, and the rest must not borrow it.
         */
        expect($byType)->toHaveKey('conversion_purchases')
            ->and($byType)->toHaveKey('conversion_add_cart')
            ->and($byType['conversion_purchases']->actionType)->toBe(ConversionActionType::Purchase)
            ->and($byType['conversion_add_cart']->actionType)->toBe(ConversionActionType::AddToCart)
            ->and($byType['conversion_purchases']->valueMinor)->toBeGreaterThan(0)
            ->and($byType['conversion_add_cart']->valueMinor)->toBeNull();
    });

    it('resolves the date from the timeseries entry', function (): void {
        $row = snapFixtures('2026-10-06')->rows(Provider::Snapchat, 'insights_ad')[0];
        $insight = (new SnapchatMapper)->insight($row, AdEntityType::Ad, 'SAR');

        expect($insight->date->format('Y-m-d'))->toBe('2026-09-22');
    });
});

describe('unknown values', function (): void {
    it('does not guess at a status it has not seen', function (): void {
        $mapper = new SnapchatMapper;

        expect($mapper->status('SOMETHING_NEW', null))->toBe(EntityStatus::Unknown)
            ->and($mapper->status(null, null))->toBe(EntityStatus::Unknown)
            // An unrecognised delivery reason must not stop a live campaign appearing.
            ->and($mapper->status('ACTIVE', ['SOME_NEW_REASON']))->toBe(EntityStatus::Active);
    });
});
