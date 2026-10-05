<?php

declare(strict_types=1);

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\MetaMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| Meta normalisation
|--------------------------------------------------------------------------
|
| Exercised against the SANDBOX fixtures, which hold real Graph API shapes. The
| point of these tests is not that the mapper runs, but that it reads Meta's
| conventions correctly: minor-unit budget strings, decimal spend strings,
| effective_status over status, the ODAX objective rename, and `actions` arrays
| whose nested categories must not be summed.
|
*/

function metaFixtures(): SandboxFixtures
{
    return new SandboxFixtures(
        dirname(__DIR__, 4).'/database/fixtures/providers',
        new DateTimeImmutable('2026-10-05'),
    );
}

/**
 * Whether a row reports a given Meta action type.
 *
 * Written against arrays rather than collections because the fixture rows are
 * decoded JSON: `mixed` all the way down, and a collection would only move the
 * type question somewhere less obvious.
 *
 * @param  array<string, mixed>  $row
 */
function metaReports(array $row, string $actionType): bool
{
    $actions = $row['actions'] ?? null;

    foreach (is_array($actions) ? $actions : [] as $action) {
        if (is_array($action) && ($action['action_type'] ?? null) === $actionType) {
            return true;
        }
    }

    return false;
}

/**
 * One action's reported value.
 *
 * @param  array<string, mixed>  $row
 */
function metaActionValue(array $row, string $actionType): ?float
{
    $actions = $row['actions'] ?? null;

    foreach (is_array($actions) ? $actions : [] as $action) {
        if (is_array($action) && ($action['action_type'] ?? null) === $actionType) {
            return (float) ($action['value'] ?? 0);
        }
    }

    return null;
}

/**
 * The first fixture row satisfying a predicate.
 *
 * @param  callable(array<string, mixed>): bool  $matches
 * @return array<string, mixed>
 */
function metaInsightRow(string $file, callable $matches): array
{
    foreach (metaFixtures()->rows(Provider::Meta, $file) as $row) {
        if ($matches($row)) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row matched.");
}

/**
 * One conversion from a mapped insight, by its provider action type.
 *
 * @param  list<App\Application\Advertising\DTO\ConversionRecord>  $breakdown
 */
function metaConversion(array $breakdown, string $rawActionType): ?App\Application\Advertising\DTO\ConversionRecord
{
    foreach ($breakdown as $conversion) {
        if ($conversion->rawActionType === $rawActionType) {
            return $conversion;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function metaRow(string $file, string $id, string $key = 'id'): array
{
    foreach (metaFixtures()->rows(Provider::Meta, $file) as $row) {
        if (($row[$key] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row with {$key} = {$id}.");
}

describe('campaigns', function (): void {
    it('reads a daily budget as minor units, not a decimal', function (): void {
        $campaign = (new MetaMapper)->campaign(metaRow('campaigns', '23850000000000001'));

        expect($campaign)->toBeInstanceOf(CampaignRecord::class)
            ->and($campaign->budgetMode)->toBe(BudgetMode::Daily)
            // "250000" is 2,500.00 SAR. Reading it as 250,000 would overstate
            // the budget a hundredfold, and pacing would never fire.
            ->and($campaign->budgetMinor)->toBe(250_000)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('prefers lifetime budget when that is what is set', function (): void {
        $campaign = (new MetaMapper)->campaign(metaRow('campaigns', '23850000000000002'));

        expect($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->budgetMinor)->toBe(1_800_000)
            ->and($campaign->spendCapMinor)->toBe(2_000_000);
    });

    it('treats a campaign with no budget as inheriting from its ad sets', function (): void {
        $campaign = (new MetaMapper)->campaign(metaRow('campaigns', '23850000000000004'));

        expect($campaign->budgetMode)->toBe(BudgetMode::Inherited)
            ->and($campaign->budgetMinor)->toBeNull();
    });

    it('normalises status from effective_status, and keeps both words', function (): void {
        $campaign = (new MetaMapper)->campaign(metaRow('campaigns', '23850000000000002'));

        // The advertiser set it ACTIVE; Meta says it is not delivering cleanly.
        // An operator comparing screens needs to see the platform's own word.
        expect($campaign->status)->toBe(EntityStatus::NotDelivering)
            ->and($campaign->rawStatus)->toBe('ACTIVE')
            ->and($campaign->rawEffectiveStatus)->toBe('WITH_ISSUES');
    });

    it('maps both the ODAX and the legacy objective names', function (): void {
        $mapper = new MetaMapper;

        expect($mapper->campaign(metaRow('campaigns', '23850000000000001'))->objective)
            ->toBe(CampaignObjective::Sales)
            ->and($mapper->campaign(metaRow('campaigns', '23850000000000004'))->objective)
            // LEAD_GENERATION predates OUTCOME_LEADS and still exists on live
            // campaigns; dropping it would silently reclassify them as "other".
            ->toBe(CampaignObjective::Leads)
            ->and($mapper->campaign(metaRow('campaigns', '23850000000000003'))->objective)
            ->toBe(CampaignObjective::Awareness);
    });

    it('keeps the provider timestamps distinct from ours', function (): void {
        $campaign = (new MetaMapper)->campaign(metaRow('campaigns', '23850000000000001'));

        expect($campaign->providerCreatedAt?->format('Y-m-d'))->toBe('2026-08-27')
            ->and($campaign->providerUpdatedAt?->format('Y-m-d'))->toBe('2026-09-28')
            ->and($campaign->startsAt?->format('Y-m-d'))->toBe('2026-09-01')
            ->and($campaign->endsAt)->toBeNull();
    });
});

describe('ad sets', function (): void {
    it('normalises an ad set and preserves its targeting verbatim', function (): void {
        $adGroup = (new MetaMapper)->adGroup(metaRow('adsets', '23850000001000001'));

        expect($adGroup)->toBeInstanceOf(AdGroupRecord::class)
            ->and($adGroup->campaignExternalId)->toBe('23850000000000001')
            ->and($adGroup->optimizationGoal)->toBe('OFFSITE_CONVERSIONS')
            ->and($adGroup->billingEvent)->toBe('IMPRESSIONS')
            // Targeting is kept as the provider shaped it: we neither flatten
            // nor interpret it, because an incorrect reading here changes who
            // an operator believes the ads are reaching.
            ->and($adGroup->targeting['geo_locations']['countries'] ?? null)->toBe(['SA'])
            ->and($adGroup->metadata['promoted_object']['custom_event_type'] ?? null)->toBe('PURCHASE');
    });

    it('reads a bid amount as minor units', function (): void {
        $adGroup = (new MetaMapper)->adGroup(metaRow('adsets', '23850000001000003'));

        expect($adGroup->bidAmountMinor)->toBe(1_800)
            ->and($adGroup->budgetMode)->toBe(BudgetMode::Lifetime);
    });

    it('distinguishes paused-by-parent from paused directly', function (): void {
        $adGroup = (new MetaMapper)->adGroup(metaRow('adsets', '23850000001000004'));

        expect($adGroup->status)->toBe(EntityStatus::Paused)
            ->and($adGroup->rawStatus)->toBe('PAUSED')
            // CAMPAIGN_PAUSED explains that resuming this ad set alone will not
            // make it deliver.
            ->and($adGroup->rawEffectiveStatus)->toBe('CAMPAIGN_PAUSED');
    });
});

describe('ads', function (): void {
    it('links an ad to its ad set, campaign and creative', function (): void {
        $ad = (new MetaMapper)->ad(metaRow('ads', '23850000002000001'));

        expect($ad)->toBeInstanceOf(AdRecord::class)
            ->and($ad->adGroupExternalId)->toBe('23850000001000001')
            ->and($ad->campaignExternalId)->toBe('23850000000000001')
            ->and($ad->creativeExternalId)->toBe('23850000003000001')
            ->and($ad->status)->toBe(EntityStatus::Active);
    });

    it('surfaces why a platform rejected an ad', function (): void {
        $ad = (new MetaMapper)->ad(metaRow('ads', '23850000002000005'));

        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->rawEffectiveStatus)->toBe('DISAPPROVED')
            // Without the reason, an operator can only guess what to change.
            ->and($ad->reviewFeedback)->toContain('prohibited health claim');
    });
});

describe('creatives', function (): void {
    it('recognises a carousel by its child attachments and keeps card order', function (): void {
        $creative = (new MetaMapper)->creative(metaRow('adcreatives', '23850000003000001'));

        expect($creative)->toBeInstanceOf(CreativeRecord::class)
            ->and($creative->format)->toBe(CreativeFormat::Carousel)
            ->and($creative->assets)->toHaveCount(3)
            ->and($creative->assets[0]->position)->toBe(0)
            ->and($creative->assets[2]->position)->toBe(2)
            ->and($creative->callToAction)->toBe('SHOP_NOW')
            ->and($creative->destinationUrl)->toContain('/alhambra/ramadan');
    });

    it('reads Arabic copy without mangling it', function (): void {
        $creative = (new MetaMapper)->creative(metaRow('adcreatives', '23850000003000001'));

        // Arabic ad copy is the normal case for this product, not an edge case.
        expect($creative->body)->toBe('عروض رمضان تبدأ الآن — خصم حتى ٤٠٪')
            ->and($creative->title)->toBe('تسوق تخفيضات رمضان');
    });

    it('recognises a video creative and its video id', function (): void {
        $creative = (new MetaMapper)->creative(metaRow('adcreatives', '23850000003000002'));

        expect($creative->format)->toBe(CreativeFormat::Video)
            ->and($creative->assets)->toHaveCount(1)
            ->and($creative->assets[0]->kind)->toBe('video')
            ->and($creative->assets[0]->externalId)->toBe('sandbox_video_9001');
    });

    it('recognises a single image creative', function (): void {
        $creative = (new MetaMapper)->creative(metaRow('adcreatives', '23850000003000003'));

        expect($creative->format)->toBe(CreativeFormat::Image)
            ->and($creative->callToAction)->toBe('LEARN_MORE');
    });
});

describe('insights', function (): void {
    it('reads spend as a decimal string and stores it in minor units', function (): void {
        $row = metaFixtures()->rows(Provider::Meta, 'insights_ad')[0];
        $insight = (new MetaMapper)->insight($row, AdEntityType::Ad, 'SAR');

        expect($insight)->toBeInstanceOf(InsightRecord::class)
            ->and($insight->spendMinor)->toBe((int) round((float) $row['spend'] * 100))
            ->and($insight->currency)->toBe('SAR');
    });

    it('never double counts purchases across nested action categories', function (): void {
        $row = metaInsightRow('insights_ad', static fn (array $r): bool => metaReports($r, 'purchase'));
        $insight = (new MetaMapper)->insight($row, AdEntityType::Ad, 'SAR');

        /*
         * Meta reports the same purchase under `purchase`,
         * `offsite_conversion.fb_pixel_purchase` and `omni_purchase`. Summing
         * the array would triple the count and divide ROAS by three.
         */
        expect($insight->conversions)->toBe(metaActionValue($row, 'purchase'));
    });

    it('reports null for a metric the row does not carry, never zero', function (): void {
        $row = metaInsightRow(
            'insights_ad',
            static fn (array $r): bool => ! isset($r['video_p100_watched_actions']),
        );
        $insight = (new MetaMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // A non-video ad has no completion rate. Zero would read as "nobody
        // finished it", which is a performance claim about something that never
        // had a video to finish.
        expect($insight->videoCompletions)->toBeNull();
    });

    it('breaks conversions out by their provider action type, with values', function (): void {
        // A day with both, because the funnel is the point: an add-to-cart
        // without a purchase is a different story from one with.
        $row = metaInsightRow(
            'insights_ad',
            static fn (array $r): bool => metaReports($r, 'add_to_cart') && metaReports($r, 'purchase'),
        );
        $breakdown = (new MetaMapper)->insight($row, AdEntityType::Ad, 'SAR')->conversionBreakdown;

        expect(metaConversion($breakdown, 'add_to_cart')?->actionType)->toBe(ConversionActionType::AddToCart)
            ->and(metaConversion($breakdown, 'purchase')?->actionType)->toBe(ConversionActionType::Purchase)
            // Only the actions Meta priced carry a value; an initiate_checkout
            // has a count and no money attached, and must stay that way.
            ->and(metaConversion($breakdown, 'purchase')?->valueMinor)->toBeGreaterThan(0)
            ->and(metaConversion($breakdown, 'initiate_checkout')?->valueMinor)->toBeNull();
    });

    it('labels an engagement action as engagement, not a conversion', function (): void {
        $row = metaFixtures()->rows(Provider::Meta, 'insights_ad')[0];
        $insight = (new MetaMapper)->insight($row, AdEntityType::Ad, 'SAR');

        // post_engagement arrives in the same `actions` array as purchases.
        // Counting it as a conversion would make every campaign look superb.
        expect(metaConversion($insight->conversionBreakdown, 'post_engagement')?->actionType)
            ->toBe(ConversionActionType::Other)
            ->and($insight->engagements)->toBeGreaterThan(0);
    });

    it('takes the entity id from the field matching the level requested', function (): void {
        $mapper = new MetaMapper;
        $row = metaFixtures()->rows(Provider::Meta, 'insights_ad')[0];

        expect($mapper->insight($row, AdEntityType::Ad, 'SAR')->externalEntityId)->toBe($row['ad_id'])
            ->and($mapper->insight($row, AdEntityType::AdGroup, 'SAR')->externalEntityId)->toBe($row['adset_id'])
            ->and($mapper->insight($row, AdEntityType::Campaign, 'SAR')->externalEntityId)->toBe($row['campaign_id']);
    });
});

describe('unknown values', function (): void {
    it('falls back to Unknown rather than guessing a status', function (): void {
        $mapper = new MetaMapper;

        // A status Meta adds next year must not silently become "active".
        expect($mapper->status('SOME_FUTURE_STATUS'))->toBe(EntityStatus::Unknown)
            ->and($mapper->status(null))->toBe(EntityStatus::Unknown);
    });

    it('falls back to Other rather than guessing an objective', function (): void {
        expect((new MetaMapper)->objective('OUTCOME_SOMETHING_NEW'))->toBe(CampaignObjective::Other);
    });
});
