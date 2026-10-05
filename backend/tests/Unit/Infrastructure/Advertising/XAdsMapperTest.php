<?php

declare(strict_types=1);

use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\XAdsMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/*
|--------------------------------------------------------------------------
| X Ads normalisation
|--------------------------------------------------------------------------
|
| A fifth set of conventions, and the first that is structurally different rather
| than merely differently spelled: X reports performance as a *time series*, one array
| per metric aligned to the requested range, so one response row is a whole range.
| The other three differences this file pins down are that objectives live on the line
| item, that reach is a separate endpoint, and that money is micros.
|
*/

function xFixtures(string $today = '2026-10-06'): SandboxFixtures
{
    return new SandboxFixtures(dirname(__DIR__, 4).'/database/fixtures/providers', new DateTimeImmutable($today));
}

/** @return array<string, mixed> */
function xRow(string $file, string $id): array
{
    foreach (xFixtures()->rows(Provider::X, $file) as $row) {
        if (($row['id'] ?? null) === $id) {
            return $row;
        }
    }

    throw new RuntimeException("No {$file} row with id {$id}.");
}

/**
 * The metrics of the first stats row for an entity.
 *
 * @return array<string, mixed>
 */
function xMetrics(string $file, string $id): array
{
    $row = xRow($file, $id);
    $entry = $row['id_data'][0] ?? [];
    $metrics = is_array($entry) ? ($entry['metrics'] ?? []) : [];

    /** @var array<string, mixed> */
    return is_array($metrics) ? $metrics : [];
}

describe('campaigns', function (): void {
    it('converts a micros daily budget into minor units', function (): void {
        $campaign = (new XAdsMapper)->campaign(xRow('campaigns', '92ph'), 'SAR');

        // 900,000,000 micros is 900.00 SAR — the same column Meta's minor-unit strings
        // land in, so a cross-platform total means something.
        expect($campaign->budgetMinor)->toBe(90_000)
            ->and($campaign->budgetMode)->toBe(BudgetMode::Daily)
            ->and($campaign->currency)->toBe('SAR');
    });

    it('claims no objective, because X keeps it on the line item', function (): void {
        $campaign = (new XAdsMapper)->campaign(xRow('campaigns', '92ph'), 'SAR');

        /*
         * A campaign with two line items can be pursuing two objectives at once.
         * Picking one for the campaign would be a claim the provider does not make.
         */
        expect($campaign->rawObjective)->toBeNull()
            ->and($campaign->objective)->toBe(CampaignObjective::Other);
    });

    it('reads a total budget as a lifetime budget and as a cap', function (): void {
        $campaign = (new XAdsMapper)->campaign(xRow('campaigns', '92pi'), 'SAR');

        expect($campaign->budgetMode)->toBe(BudgetMode::Lifetime)
            ->and($campaign->budgetMinor)->toBe(4_000_000)
            ->and($campaign->spendCapMinor)->toBe(4_000_000);
    });

    it('records a campaign that is active and not delivering', function (): void {
        $campaign = (new XAdsMapper)->campaign(xRow('campaigns', '92pi'), 'SAR');

        // `entity_status` is only the switch. An active campaign whose setup is
        // incomplete is not serving, and only `reasons_not_servable` says so.
        expect($campaign->status)->toBe(EntityStatus::NotDelivering)
            ->and($campaign->rawStatus)->toBe('ACTIVE');
    });

    it('calls a paused campaign paused, not merely unservable', function (): void {
        $campaign = (new XAdsMapper)->campaign(xRow('campaigns', '92pj'), 'SAR');

        /*
         * X lists PAUSED_BY_ADVERTISER among the reasons a paused campaign cannot
         * serve. Both are true; "paused" is the one an operator can act on.
         */
        expect($campaign->status)->toBe(EntityStatus::Paused);
    });

    it('treats a deleted entity as removed whatever its status says', function (): void {
        $campaign = (new XAdsMapper)->campaign(
            ['id' => '92pz', 'entity_status' => 'ACTIVE', 'deleted' => true],
            'SAR',
        );

        expect($campaign->status)->toBe(EntityStatus::Removed);
    });

    it('calls an unservable campaign not delivering even for a reason it cannot read', function (): void {
        $campaign = (new XAdsMapper)->campaign(
            [
                'id' => '92pz',
                'entity_status' => 'ACTIVE',
                'servable' => false,
                'reasons_not_servable' => ['SOME_CODE_X_ADDED_LATER'],
            ],
            'SAR',
        );

        /*
         * Only two reason codes are documented. An undocumented one is not interpreted
         * — but `servable: false` is still the provider saying it cannot run, and the
         * code itself reaches the interface as the raw effective status.
         */
        expect($campaign->status)->toBe(EntityStatus::NotDelivering)
            ->and($campaign->rawEffectiveStatus)->toBe('SOME_CODE_X_ADDED_LATER');
    });

    it('reports an unknown status rather than assuming active', function (): void {
        $campaign = (new XAdsMapper)->campaign(['id' => '92pz', 'entity_status' => 'SOMETHING_NEW'], 'SAR');

        expect($campaign->status)->toBe(EntityStatus::Unknown);
    });
});

describe('line items', function (): void {
    it('maps a line item onto an ad group, with the campaign it belongs to', function (): void {
        $adGroup = (new XAdsMapper)->adGroup(xRow('line_items', '8u94t'), 'SAR');

        expect($adGroup->campaignExternalId)->toBe('92ph')
            ->and($adGroup->optimizationGoal)->toBe('LINK_CLICKS')
            // `charge_by` is the event X bills for; `pay_by` answers a different
            // question and stays in metadata.
            ->and($adGroup->billingEvent)->toBe('LINK_CLICK')
            ->and($adGroup->metadata['pay_by'] ?? null)->toBe('LINK_CLICK');
    });

    it('keeps the objective it actually carries', function (): void {
        $mapper = new XAdsMapper;
        $adGroup = $mapper->adGroup(xRow('line_items', '8u94v'), 'SAR');

        expect($adGroup->metadata['objective'] ?? null)->toBe('VIDEO_VIEWS')
            ->and($mapper->objective('VIDEO_VIEWS'))->toBe(CampaignObjective::VideoViews)
            ->and($mapper->objective('WEBSITE_CLICKS'))->toBe(CampaignObjective::Traffic)
            ->and($mapper->objective('APP_INSTALLS'))->toBe(CampaignObjective::AppPromotion)
            ->and($mapper->objective('FOLLOWERS'))->toBe(CampaignObjective::Engagement)
            ->and($mapper->objective('REACH'))->toBe(CampaignObjective::Reach)
            // An objective X adds later must not be silently filed under an existing
            // one.
            ->and($mapper->objective('SOMETHING_NEW'))->toBe(CampaignObjective::Other);
    });

    it('converts a micros bid into minor units and drops an absent one', function (): void {
        $mapper = new XAdsMapper;

        // 2,500,000 micros is 2.50 SAR.
        expect($mapper->adGroup(xRow('line_items', '8u94u'), 'SAR')->bidAmountMinor)->toBe(250)
            // AUTO bidding carries no bid, and a null bid is not a bid of zero.
            ->and($mapper->adGroup(xRow('line_items', '8u94t'), 'SAR')->bidAmountMinor)->toBeNull();
    });

    it('reports no targeting, because X keeps it in its own collection', function (): void {
        $adGroup = (new XAdsMapper)->adGroup(xRow('line_items', '8u94t'), 'SAR');

        // Not an oversight: `targeting_criteria` is a separate request this sync does
        // not make, and an empty object says so honestly.
        expect($adGroup->targeting)->toBe([]);
    });
});

describe('promoted tweets', function (): void {
    it('reaches the hierarchy through the line item', function (): void {
        $ad = (new XAdsMapper)->ad(xRow('promoted_tweets', 'eqaad'));

        /*
         * A promoted tweet carries no campaign id. Left empty rather than guessed —
         * the store resolves the campaign from the line item it already synced.
         */
        expect($ad->adGroupExternalId)->toBe('8u94t')
            ->and($ad->campaignExternalId)->toBe('')
            // The tweet *is* the creative on X.
            ->and($ad->creativeExternalId)->toBe('1839201847362019328');
    });

    it('labels an ad by its tweet, since a promoted tweet has no name', function (): void {
        $ad = (new XAdsMapper)->ad(xRow('promoted_tweets', 'eqaad'));

        expect($ad->name)->toBe('Tweet 1839201847362019328');
    });

    it('lets a rejection override the switch', function (): void {
        $ad = (new XAdsMapper)->ad(xRow('promoted_tweets', 'eqaaf'));

        // A rejected tweet cannot run however its `entity_status` reads.
        expect($ad->status)->toBe(EntityStatus::Rejected)
            ->and($ad->rawStatus)->toBe('ACTIVE')
            ->and($ad->rawEffectiveStatus)->toBe('REJECTED');
    });
});

describe('cards', function (): void {
    it('reads the headline, the destination and the media out of the components', function (): void {
        $creative = (new XAdsMapper)->creative(xRow('cards', '5a9v1'));

        expect($creative->format)->toBe(CreativeFormat::Image)
            ->and($creative->title)->toBe('Oud Alhambra | free delivery in Riyadh')
            ->and($creative->destinationUrl)->toBe('https://alhambra-oud.example/riyadh')
            ->and($creative->assets)->toHaveCount(1)
            // A media key, not a URL: the URL lives on the media library resource.
            ->and($creative->assets[0]->externalId)->toBe('3_1323490622599176192');
    });

    it('calls a swipeable card a carousel, whatever its card type says', function (): void {
        $creative = (new XAdsMapper)->creative(xRow('cards', '5a9v2'));

        // A SWIPEABLE_MEDIA component *is* how X documents building a carousel, so it
        // settles the format even though `card_type` is the catch-all UNIFIED.
        expect($creative->format)->toBe(CreativeFormat::Carousel)
            ->and($creative->assets)->toHaveCount(3)
            ->and($creative->assets[2]->position)->toBe(2)
            // The label comes out of X's typed wrapper, not stringified whole.
            ->and($creative->callToAction)->toBe('INSTALL');
    });

    it('reports no destination for an app component, because a store id is not a link', function (): void {
        $creative = (new XAdsMapper)->creative(xRow('cards', '5a9v2'));

        expect($creative->destinationUrl)->toBeNull()
            // The component survives whole, so nothing is lost by not flattening it.
            ->and($creative->metadata['components'] ?? null)->toBeArray();
    });

    it('reports an unknown format rather than guessing from a card type', function (): void {
        $mapper = new XAdsMapper;

        expect($mapper->format('UNIFIED'))->toBe(CreativeFormat::Unknown)
            ->and($mapper->format(null))->toBe(CreativeFormat::Unknown)
            ->and($mapper->format('VIDEO_APP'))->toBe(CreativeFormat::Video);
    });
});

describe('stats', function (): void {
    it('expands a one-bucket series into one record for that day', function (): void {
        $records = (new XAdsMapper)->insights(
            xRow('insights_campaign', '92ph'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        expect($records)->toHaveCount(1)
            ->and($records[0]->date->format('Y-m-d'))->toBe('2026-09-22')
            ->and($records[0]->entityType)->toBe(AdEntityType::Campaign);
    });

    it('walks a multi-bucket series one day at a time', function (): void {
        /*
         * The shape a live DAY-granularity request over several days returns: one array
         * per metric, aligned to the requested start. Reading only the first bucket
         * would silently discard every day but the first.
         */
        $records = (new XAdsMapper)->insights(
            [
                'id' => '92ph',
                'id_data' => [[
                    'segment' => null,
                    'metrics' => [
                        'impressions' => [100, 200, 300],
                        'billed_charge_local_micro' => [1_000_000, 2_000_000, 3_000_000],
                    ],
                ]],
            ],
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-01'),
        );

        expect($records)->toHaveCount(3)
            ->and(array_map(static fn ($r): string => $r->date->format('Y-m-d'), $records))
            ->toBe(['2026-09-01', '2026-09-02', '2026-09-03'])
            ->and(array_map(static fn ($r): ?int => $r->impressions, $records))->toBe([100, 200, 300])
            ->and($records[2]->spendMinor)->toBe(300);
    });

    it('takes the series length from the longest metric, not the first', function (): void {
        // A metric with no data comes back as null rather than an array of zeros, so
        // the first key may be shorter than the series.
        $records = (new XAdsMapper)->insights(
            [
                'id' => '92ph',
                'id_data' => [[
                    'segment' => null,
                    'metrics' => ['conversion_sign_ups' => null, 'impressions' => [1, 2, 3, 4]],
                ]],
            ],
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-01'),
        );

        expect($records)->toHaveCount(4);
    });

    it('skips a segmented entry rather than counting one day twice', function (): void {
        /*
         * A segmented request returns one entry per segment. Mapping them all would
         * write several rows for one entity and one day — the same spend counted once
         * per age bracket.
         */
        $records = (new XAdsMapper)->insights(
            [
                'id' => '92ph',
                'id_data' => [
                    ['segment' => ['segment_name' => '25-34'], 'metrics' => ['impressions' => [10]]],
                    ['segment' => null, 'metrics' => ['impressions' => [40]]],
                ],
            ],
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-01'),
        );

        expect($records)->toHaveCount(1)
            ->and($records[0]->impressions)->toBe(40);
    });

    it('converts billed charge micros into minor units', function (): void {
        $metrics = xMetrics('insights_campaign', '92ph');
        $record = (new XAdsMapper)->insight(
            '92ph',
            $metrics,
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        $micros = (int) $metrics['billed_charge_local_micro'][0];

        // Truncated at the minor unit rather than rounded, as for every other micros
        // provider: a fraction of a halala is not spend anybody was billed.
        expect($record->spendMinor)->toBe(intdiv($micros, 10_000));
    });

    it('reports no reach at all, because that is a different endpoint', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            xMetrics('insights_campaign', '92ph'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        // `stats/.../reach/campaigns` is its own call with its own limits. Passing
        // impressions off as people would be the easy lie here.
        expect($record->reach)->toBeNull()
            ->and($record->impressions)->toBeGreaterThan(0);
    });

    it('reports no revenue, because X publishes conversion counts and not their worth', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            xMetrics('insights_campaign', '92ph'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        expect($record->revenueMinor)->toBeNull()
            ->and($record->conversions)->toBeGreaterThan(0);
    });

    it('keeps url clicks distinct from clicks', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            xMetrics('insights_campaign', '92ph'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        // X's nearest equivalent to a link click is its own metric, not a share of
        // `clicks` — so the two CTRs differ for a reason that is the platform's.
        expect($record->linkClicks)->toBeLessThan((int) $record->clicks)
            ->and($record->linkClicks)->toBeGreaterThan(0);
    });

    it('leaves a null metric null rather than calling it a measured zero', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            ['impressions' => [10], 'conversion_sign_ups' => null, 'engagements' => [0]],
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-01'),
        );

        /*
         * X documents that null and zero are equivalent *for it*. They are not
         * equivalent here: an aggregate has to be able to say "not reported" rather
         * than claim a measured none, so only a figure X actually sent becomes a
         * number.
         */
        expect($record->engagements)->toBe(0)
            ->and(array_column(array_map(
                static fn ($c): array => ['type' => $c->rawActionType],
                $record->conversionBreakdown,
            ), 'type'))->not->toContain('conversion_sign_ups');
    });

    it('omits a metric X did not send at all', function (): void {
        $record = (new XAdsMapper)->insight(
            '92pi',
            xMetrics('insights_campaign', '92pi'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        // A video-view line item has no link to click, and X omits the key rather than
        // nulling it. Both absences mean the same thing and must read the same way.
        expect($record->linkClicks)->toBeNull()
            ->and($record->videoViews)->toBeGreaterThan(0)
            ->and($record->videoCompletions)->toBeGreaterThan(0);
    });

    it('writes one conversion row per reported column, with no value on any of them', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            xMetrics('insights_campaign', '92ph'),
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-22'),
        );

        $types = array_map(static fn ($c): string => $c->rawActionType, $record->conversionBreakdown);

        expect($types)->toContain('conversion_purchases', 'conversion_add_to_carts', 'conversion_site_visits')
            // X reports counts in these groups, not their worth. A value assembled here
            // would be ours, not the platform's.
            ->and(array_filter(
                $record->conversionBreakdown,
                static fn ($c): bool => $c->valueMinor !== null,
            ))->toBe([]);
    });

    it('maps the conversion columns it knows and files the rest as other', function (): void {
        $mapper = new XAdsMapper;

        expect($mapper->conversionType('conversion_purchases'))->toBe(ConversionActionType::Purchase)
            ->and($mapper->conversionType('mobile_conversion_installs'))->toBe(ConversionActionType::AppInstall)
            ->and($mapper->conversionType('conversion_checkouts_initiated'))->toBe(ConversionActionType::InitiateCheckout)
            ->and($mapper->conversionType('conversion_custom'))->toBe(ConversionActionType::Custom)
            ->and($mapper->conversionType('conversion_something_new'))->toBe(ConversionActionType::Other);
    });

    it('carries a metric it cannot name into metadata instead of reporting it', function (): void {
        $record = (new XAdsMapper)->insight(
            '92ph',
            ['impressions' => [10], 'brand_lift_points' => [3]],
            AdEntityType::Campaign,
            'SAR',
            new DateTimeImmutable('2026-09-01'),
        );

        expect($record->metadata['unmapped_metrics'] ?? [])->toHaveKey('brand_lift_points');
    });
});
