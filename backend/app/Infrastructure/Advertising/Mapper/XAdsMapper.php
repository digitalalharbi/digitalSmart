<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Mapper;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\ConversionRecord;
use App\Application\Advertising\DTO\CreativeAssetRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\BudgetMode;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\ConversionActionType;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Advertising\ValueObject\Money;
use DateTimeImmutable;

/**
 * X Ads API v12 → digitalSmart.
 *
 * Field names, enum values and response shapes were read from X's published
 * reference. Four things differ from every other provider here, and each one shapes
 * the code below:
 *
 *   - **The objective is on the line item, not the campaign.** An X campaign has a
 *     budget, a funding instrument and a schedule and no objective at all, so a
 *     campaign record carries none rather than one borrowed from its children.
 *   - **Stats are a time series, not a row per date.** Each metric comes back as an
 *     array aligned to the requested range at the requested granularity, so one
 *     response row is a whole range and has to be expanded — see `insights()`.
 *   - **Reach is a separate endpoint.** `stats/.../reach/campaigns` is its own call
 *     with its own range limits, so the stats rows carry no reach and this mapper
 *     reports none rather than substituting frequency-derived arithmetic.
 *   - **Money is micros**, suffixed `_local_micro`, the same convention as Google
 *     and Snapchat.
 *
 * Ad groups here are **line items** and ads are **promoted tweets**; the names in
 * comments stay X's own so they can be compared with the documentation.
 */
final class XAdsMapper
{
    /**
     * Metric names confirmed against X's analytics reference.
     *
     * Anything else X sends is carried into metadata rather than guessed at — a
     * metric this product cannot name is not a metric it should report.
     */
    private const VERIFIED_METRICS = [
        // ENGAGEMENT
        'impressions', 'engagements', 'clicks', 'retweets', 'replies', 'likes',
        'follows', 'app_clicks', 'url_clicks', 'qualified_impressions',
        'carousel_swipes', 'card_engagements',
        // BILLING
        'billed_engagements', 'billed_charge_local_micro',
        // VIDEO
        'video_total_views', 'video_views_25', 'video_views_50', 'video_views_75',
        'video_views_100', 'video_cta_clicks', 'video_content_starts',
        'video_3s100pct_views', 'video_6s_views', 'video_15s_views',
        // WEB_CONVERSION
        'conversion_purchases', 'conversion_sign_ups', 'conversion_site_visits',
        'conversion_downloads', 'conversion_custom', 'conversion_add_to_carts',
        'conversion_checkouts_initiated', 'conversion_content_views',
        'conversion_payment_info_additions', 'conversion_add_to_wishlists',
        'conversion_searches', 'conversion_landing_page_views',
        'conversion_subscriptions',
        // MOBILE_CONVERSION
        'mobile_conversion_installs', 'mobile_conversion_purchases',
        'mobile_conversion_sign_ups', 'mobile_conversion_site_visits',
        'mobile_conversion_add_to_carts', 'mobile_conversion_checkouts_initiated',
        'mobile_conversion_content_views', 'mobile_conversion_searches',
    ];

    /**
     * Conversion columns mapped to our vocabulary.
     *
     * X reports conversions as named columns rather than an action array, so the
     * breakdown is built from this list. Only the columns whose names are confirmed
     * appear; a column outside it lands in `unmapped_metrics` instead of being
     * assigned a meaning.
     */
    private const CONVERSION_COLUMNS = [
        'conversion_purchases' => ConversionActionType::Purchase,
        'conversion_sign_ups' => ConversionActionType::CompleteRegistration,
        'conversion_site_visits' => ConversionActionType::LandingPageView,
        'conversion_landing_page_views' => ConversionActionType::LandingPageView,
        'conversion_add_to_carts' => ConversionActionType::AddToCart,
        'conversion_checkouts_initiated' => ConversionActionType::InitiateCheckout,
        'conversion_content_views' => ConversionActionType::ViewContent,
        'conversion_searches' => ConversionActionType::Search,
        'conversion_add_to_wishlists' => ConversionActionType::Other,
        'conversion_payment_info_additions' => ConversionActionType::Other,
        'conversion_subscriptions' => ConversionActionType::Subscribe,
        'conversion_downloads' => ConversionActionType::Other,
        'conversion_custom' => ConversionActionType::Custom,
        'mobile_conversion_installs' => ConversionActionType::AppInstall,
        'mobile_conversion_purchases' => ConversionActionType::Purchase,
        'mobile_conversion_sign_ups' => ConversionActionType::CompleteRegistration,
    ];

    /** @param array<string, mixed> $raw a `campaigns` data item */
    public function campaign(array $raw, string $currency): CampaignRecord
    {
        $campaignCurrency = $this->string($raw, 'currency') ?? $currency;
        $daily = $this->int($raw, 'daily_budget_amount_local_micro');
        $total = $this->int($raw, 'total_budget_amount_local_micro');

        /*
         * X allows both at once: the daily figure paces delivery and the total caps
         * the campaign. A campaign with both is reported as daily-budgeted with a cap,
         * which is what an operator sees in X's own interface.
         */
        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily > 0 => [BudgetMode::Daily, Money::fromMicros($daily, $campaignCurrency)->minorUnits],
            $total !== null && $total > 0 => [BudgetMode::Lifetime, Money::fromMicros($total, $campaignCurrency)->minorUnits],
            // No budget on the campaign means the line items carry it.
            default => [BudgetMode::Inherited, null],
        };

        return new CampaignRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled campaign',
            status: $this->status($raw),
            rawStatus: $this->string($raw, 'entity_status'),
            rawEffectiveStatus: $this->effectiveStatus($raw),
            /*
             * No objective, and none inferred. On X the objective is a property of the
             * line item, so a campaign with two line items can be pursuing two
             * objectives at once; picking one for the campaign would be a claim the
             * provider does not make.
             */
            rawObjective: null,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            spendCapMinor: $total === null || $total === 0
                ? null
                : Money::fromMicros($total, $campaignCurrency)->minorUnits,
            currency: $campaignCurrency,
            // X has no buying-type field; reservation products are bought as line-item
            // product types (SPOTLIGHT, TREND) rather than a campaign-level mode.
            buyingType: null,
            // Bidding is configured per line item.
            bidStrategy: null,
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            metadata: array_filter([
                'funding_instrument_id' => $this->string($raw, 'funding_instrument_id'),
                'standard_delivery' => $raw['standard_delivery'] ?? null,
                'budget_optimization' => $this->string($raw, 'budget_optimization'),
                'duration_in_days' => $raw['duration_in_days'] ?? null,
                'frequency_cap' => $raw['frequency_cap'] ?? null,
                'servable' => $raw['servable'] ?? null,
                'reasons_not_servable' => $raw['reasons_not_servable'] ?? null,
                'purchase_order_number' => $this->string($raw, 'purchase_order_number'),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /** @param array<string, mixed> $raw a `line_items` data item */
    public function adGroup(array $raw, string $currency): AdGroupRecord
    {
        $itemCurrency = $this->string($raw, 'currency') ?? $currency;
        $daily = $this->int($raw, 'daily_budget_amount_local_micro');
        $total = $this->int($raw, 'total_budget_amount_local_micro');
        $bid = $this->int($raw, 'bid_amount_local_micro');

        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null && $daily > 0 => [BudgetMode::Daily, Money::fromMicros($daily, $itemCurrency)->minorUnits],
            $total !== null && $total > 0 => [BudgetMode::Lifetime, Money::fromMicros($total, $itemCurrency)->minorUnits],
            default => [BudgetMode::Inherited, null],
        };

        return new AdGroupRecord(
            externalId: (string) ($raw['id'] ?? ''),
            campaignExternalId: (string) ($raw['campaign_id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled line item',
            status: $this->status($raw),
            rawStatus: $this->string($raw, 'entity_status'),
            rawEffectiveStatus: $this->effectiveStatus($raw),
            // `goal` is what X optimises towards; `objective` is the campaign intent it
            // belongs to, which is kept below alongside it.
            optimizationGoal: $this->string($raw, 'goal'),
            /*
             * `charge_by` is the event X bills for. `pay_by` is the unit the bid is
             * expressed in, which is a different question, so it stays in metadata
             * rather than being flattened into this one.
             */
            billingEvent: $this->string($raw, 'charge_by'),
            bidAmountMinor: $bid === null || $bid === 0
                ? null
                : Money::fromMicros($bid, $itemCurrency)->minorUnits,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            currency: $itemCurrency,
            startsAt: $this->date($this->string($raw, 'start_time')),
            endsAt: $this->date($this->string($raw, 'end_time')),
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            /*
             * Empty, and correctly so: X keeps targeting in its own
             * `targeting_criteria` collection, one row per criterion, rather than on
             * the line item. Reading it is a separate request this sync does not make,
             * and an empty object here says that honestly.
             */
            targeting: [],
            metadata: array_filter([
                'objective' => $this->string($raw, 'objective'),
                'product_type' => $this->string($raw, 'product_type'),
                'placements' => $raw['placements'] ?? null,
                'bid_strategy' => $this->string($raw, 'bid_strategy'),
                'pay_by' => $this->string($raw, 'pay_by'),
                'target_cpa_local_micro' => $raw['target_cpa_local_micro'] ?? null,
                'standard_delivery' => $raw['standard_delivery'] ?? null,
                'automatic_tweet_promotion' => $raw['automatic_tweet_promotion'] ?? null,
                'frequency_cap' => $raw['frequency_cap'] ?? null,
                'categories' => $raw['categories'] ?? null,
                'advertiser_domain' => $this->string($raw, 'advertiser_domain'),
                'primary_web_event_tag' => $this->string($raw, 'primary_web_event_tag'),
                'android_app_store_identifier' => $this->string($raw, 'android_app_store_identifier'),
                'ios_app_store_identifier' => $this->string($raw, 'ios_app_store_identifier'),
                'servable' => $raw['servable'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /** @param array<string, mixed> $raw a `promoted_tweets` data item */
    public function ad(array $raw): AdRecord
    {
        $approval = $this->string($raw, 'approval_status');

        return new AdRecord(
            externalId: (string) ($raw['id'] ?? ''),
            adGroupExternalId: (string) ($raw['line_item_id'] ?? ''),
            /*
             * A promoted tweet carries no campaign id: on X the hierarchy is reached
             * through the line item. Left empty rather than guessed — the store
             * resolves the campaign from the line item it has already synced.
             */
            campaignExternalId: '',
            /*
             * A promoted tweet has no name of its own. The tweet is the creative, and
             * X's own interface labels the row by its tweet, so the id goes in the name
             * rather than leaving an operator with "Untitled" repeated down a list.
             */
            name: $this->tweetLabel($raw),
            status: $this->adStatus($raw, $approval),
            rawStatus: $this->string($raw, 'entity_status'),
            rawEffectiveStatus: $approval,
            // The tweet *is* the creative on X; there is no separate creative entity
            // for a promoted tweet, which is why this points at the tweet id.
            creativeExternalId: $this->string($raw, 'tweet_id'),
            /*
             * X publishes an approval verdict but no reason text on this object, so
             * there is nothing to show an operator beyond the verdict itself.
             */
            reviewFeedback: null,
            providerCreatedAt: $this->date($this->string($raw, 'created_at')),
            providerUpdatedAt: $this->date($this->string($raw, 'updated_at')),
            metadata: array_filter([
                'tweet_id' => $this->string($raw, 'tweet_id'),
                'approval_status' => $approval,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * A card, which is X's reusable creative unit.
     *
     * The tweet in a promoted tweet is what runs; a card is the media and
     * call-to-action a tweet can carry. X assembles a card from *components* and
     * derives `card_type` from them, so the components are where the medium, the
     * headline, the button and the destination actually live:
     *
     *   MEDIA           → one `media_key`
     *   SWIPEABLE_MEDIA → `media_keys`, two to six — this is how a carousel is made
     *   DETAILS         → `title` and `destination`
     *   BUTTON          → `label` and `destination`
     *
     * `title` and `label` are typed wrappers — `{"type": "ENUM", "value": "INSTALL"}`
     * in X's own example — so the value is read out of the wrapper rather than the
     * wrapper being stringified.
     *
     * @param  array<string, mixed>  $raw  a `cards/all` data item
     */
    public function creative(array $raw): CreativeRecord
    {
        $components = $this->components($raw);
        $details = $components['DETAILS'] ?? [];
        $button = $components['BUTTON'] ?? [];

        return new CreativeRecord(
            externalId: (string) ($raw['id'] ?? ''),
            name: $this->string($raw, 'name') ?? 'Untitled card',
            format: $this->format($this->string($raw, 'card_type'), $components),
            title: $this->wrapped($details, 'title'),
            /*
             * No body copy on a card. The words around a card are the tweet's, and the
             * tweet is a different resource this sync does not read.
             */
            body: null,
            callToAction: $this->wrapped($button, 'label'),
            destinationUrl: $this->destination($details) ?? $this->destination($button),
            /*
             * A card references media by key; the URL of that media lives on the media
             * library resource, which is a separate request. A key is not a URL, so
             * nothing is reported here rather than one assembled from the key.
             */
            thumbnailUrl: null,
            previewUrl: null,
            assets: $this->assets($components),
            metadata: array_filter([
                'card_type' => $this->string($raw, 'card_type'),
                // The handle a tweet uses to attach this card.
                'card_uri' => $this->string($raw, 'card_uri'),
                'components' => $raw['components'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * One stats row expanded into one record per bucket.
     *
     * X does not return a row per date. It returns, for each entity, one array per
     * metric aligned to the requested range at the requested granularity:
     *
     *   {"id": "dvcz7", "id_data": [{"segment": null, "metrics": {"impressions": [0, 2792, …]}}]}
     *
     * So bucket *i* of every metric belongs to the same day, and the day is the
     * requested start plus *i* — the response states the length, not the dates. This
     * sync only ever asks for DAY granularity, which is why the step is a day.
     *
     * Segmented entries are skipped. A segmented request returns one entry per
     * segment, and mapping them all would write several rows for one entity and one
     * day — the same spend counted once per age bracket.
     *
     * @param  array<string, mixed>  $raw
     * @return list<InsightRecord>
     */
    public function insights(
        array $raw,
        AdEntityType $level,
        string $currency,
        DateTimeImmutable $start,
    ): array {
        $entityId = (string) ($raw['id'] ?? '');
        $records = [];

        foreach ($this->list($raw, 'id_data') as $entry) {
            if (($entry['segment'] ?? null) !== null) {
                continue;
            }

            $metrics = $this->object($entry, 'metrics');

            for ($bucket = 0; $bucket < $this->bucketCount($metrics); $bucket++) {
                $records[] = $this->insight(
                    $entityId,
                    $metrics,
                    $level,
                    $currency,
                    $start->modify("+{$bucket} days"),
                    $bucket,
                );
            }
        }

        return $records;
    }

    /**
     * One bucket of one entity's time series.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function insight(
        string $entityId,
        array $metrics,
        AdEntityType $level,
        string $currency,
        DateTimeImmutable $date,
        int $bucket = 0,
    ): InsightRecord {
        $spend = $this->bucketInt($metrics, 'billed_charge_local_micro', $bucket);

        return new InsightRecord(
            entityType: $level,
            externalEntityId: $entityId,
            date: $date,
            currency: $currency,
            // Micros, like every money field in this API.
            spendMinor: $spend === null ? null : Money::fromMicros($spend, $currency)->minorUnits,
            /*
             * X publishes no revenue metric in the groups this sync requests. Purchase
             * *counts* are reported; their value is not, so nothing is reported here
             * rather than a figure assembled from a count and an assumed basket.
             */
            revenueMinor: null,
            impressions: $this->bucketInt($metrics, 'impressions', $bucket),
            /*
             * Reach is a different endpoint on X — `stats/.../reach/campaigns`, with its
             * own range limits — and is not part of a stats response. None is reported
             * rather than impressions passed off as people.
             */
            reach: null,
            clicks: $this->bucketInt($metrics, 'clicks', $bucket),
            // X's nearest equivalent to a link click, and its own metric rather than a
            // share of `clicks`.
            linkClicks: $this->bucketInt($metrics, 'url_clicks', $bucket),
            videoViews: $this->bucketInt($metrics, 'video_total_views', $bucket),
            videoCompletions: $this->bucketInt($metrics, 'video_views_100', $bucket),
            engagements: $this->bucketInt($metrics, 'engagements', $bucket),
            /*
             * Purchases as the headline, as for Snapchat. X publishes no conversion
             * total: every type is its own column, and adding them would count one
             * purchase again as a content view and again as a checkout. The breakdown
             * below carries each column as reported.
             */
            conversions: $this->bucketFloat($metrics, 'conversion_purchases', $bucket),
            conversionBreakdown: $this->conversions($metrics, $bucket),
            metadata: array_filter([
                'billed_engagements' => $this->bucketInt($metrics, 'billed_engagements', $bucket),
                'retweets' => $this->bucketInt($metrics, 'retweets', $bucket),
                'replies' => $this->bucketInt($metrics, 'replies', $bucket),
                'likes' => $this->bucketInt($metrics, 'likes', $bucket),
                'follows' => $this->bucketInt($metrics, 'follows', $bucket),
                'app_clicks' => $this->bucketInt($metrics, 'app_clicks', $bucket),
                'qualified_impressions' => $this->bucketInt($metrics, 'qualified_impressions', $bucket),
                'carousel_swipes' => $this->bucketInt($metrics, 'carousel_swipes', $bucket),
                'card_engagements' => $this->bucketInt($metrics, 'card_engagements', $bucket),
                'video_views_25' => $this->bucketInt($metrics, 'video_views_25', $bucket),
                'video_views_50' => $this->bucketInt($metrics, 'video_views_50', $bucket),
                'video_views_75' => $this->bucketInt($metrics, 'video_views_75', $bucket),
                'video_content_starts' => $this->bucketInt($metrics, 'video_content_starts', $bucket),
                'video_cta_clicks' => $this->bucketInt($metrics, 'video_cta_clicks', $bucket),
                'video_3s100pct_views' => $this->bucketInt($metrics, 'video_3s100pct_views', $bucket),
                'video_6s_views' => $this->bucketInt($metrics, 'video_6s_views', $bucket),
                'video_15s_views' => $this->bucketInt($metrics, 'video_15s_views', $bucket),
                'unmapped_metrics' => $this->unverifiedMetrics($metrics),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * `entity_status`, refined by whether X says the entity can actually serve.
     *
     * `entity_status` is only the switch the advertiser set. An ACTIVE campaign that
     * has exhausted its funding instrument, fallen outside its schedule or has no
     * servable line items is not delivering, and only `servable` and
     * `reasons_not_servable` say so.
     *
     * @param  array<string, mixed>  $raw
     */
    public function status(array $raw): EntityStatus
    {
        if (($raw['deleted'] ?? false) === true) {
            return EntityStatus::Removed;
        }

        $entityStatus = strtoupper($this->string($raw, 'entity_status') ?? '');

        /*
         * The switch wins when it is off. X reports `PAUSED_BY_ADVERTISER` among the
         * reasons a paused entity is not servable, and "paused" is the more useful of
         * the two answers — the reasons below refine an entity the advertiser has
         * actually turned on.
         */
        if ($entityStatus === 'PAUSED') {
            return EntityStatus::Paused;
        }

        if ($entityStatus === 'DRAFT') {
            return EntityStatus::NotDelivering;
        }

        /*
         * Only two reason codes are documented, and only those two are interpreted.
         * Reading meaning into a code X has not published would be guessing at which
         * of eight states a campaign is in from the shape of a word.
         */
        foreach ($this->reasons($raw) as $reason) {
            $fromReason = match ($reason) {
                'PAUSED_BY_ADVERTISER' => EntityStatus::Paused,
                'INCOMPLETE' => EntityStatus::NotDelivering,
                default => null,
            };

            if ($fromReason instanceof EntityStatus) {
                return $fromReason;
            }
        }

        /*
         * Any other reason still means it is not delivering — that is what `servable`
         * says, whatever the code behind it. The code itself reaches the interface as
         * the raw effective status, so an operator sees X's own word for it rather
         * than ours.
         */
        if (($raw['servable'] ?? null) === false) {
            return EntityStatus::NotDelivering;
        }

        return $entityStatus === 'ACTIVE' ? EntityStatus::Active : EntityStatus::Unknown;
    }

    /**
     * A promoted tweet's approval verdict overrides its switch.
     *
     * A rejected tweet cannot run however its `entity_status` reads.
     *
     * @param  array<string, mixed>  $raw
     */
    public function adStatus(array $raw, ?string $approvalStatus): EntityStatus
    {
        return match (strtoupper((string) $approvalStatus)) {
            'REJECTED' => EntityStatus::Rejected,
            'PENDING', 'UNDER_REVIEW' => EntityStatus::InReview,
            default => $this->status($raw),
        };
    }

    /**
     * X's line-item objective enum.
     *
     * Read from a line item rather than a campaign, because that is where X keeps it.
     */
    public function objective(?string $raw): CampaignObjective
    {
        return match (strtoupper((string) $raw)) {
            'REACH' => CampaignObjective::Reach,
            'FOLLOWERS', 'ENGAGEMENTS' => CampaignObjective::Engagement,
            'VIDEO_VIEWS', 'PREROLL_VIEWS' => CampaignObjective::VideoViews,
            'WEBSITE_CLICKS' => CampaignObjective::Traffic,
            'APP_INSTALLS', 'APP_ENGAGEMENTS' => CampaignObjective::AppPromotion,
            default => CampaignObjective::Other,
        };
    }

    /**
     * A card's format, from its components first and its `card_type` second.
     *
     * A SWIPEABLE_MEDIA component *is* a carousel — that is how X documents building
     * one — so it settles the question regardless of what `card_type` says.
     *
     * Otherwise the medium is read from the first token of `card_type`. X derives that
     * field from the components and the documented example is `IMAGE_APP`, so the
     * leading token is the medium and the trailing one the destination kind. Reading
     * the prefix rather than listing values keeps this to what the documentation
     * shows: a card type whose prefix is neither is reported as unknown rather than
     * guessed from the name.
     *
     * @param  array<string, array<string, mixed>>  $components  keyed by component type
     */
    public function format(?string $cardType, array $components = []): CreativeFormat
    {
        if (isset($components['SWIPEABLE_MEDIA'])) {
            return CreativeFormat::Carousel;
        }

        $type = strtoupper((string) $cardType);

        return match (true) {
            str_starts_with($type, 'IMAGE_') => CreativeFormat::Image,
            str_starts_with($type, 'VIDEO_') => CreativeFormat::Video,
            default => CreativeFormat::Unknown,
        };
    }

    /** X's conversion column names onto our vocabulary. */
    public function conversionType(string $column): ConversionActionType
    {
        return self::CONVERSION_COLUMNS[$column] ?? ConversionActionType::Other;
    }

    /**
     * One record per conversion column that reported a figure.
     *
     * @param  array<string, mixed>  $metrics
     * @return list<ConversionRecord>
     */
    private function conversions(array $metrics, int $bucket): array
    {
        $records = [];

        foreach (self::CONVERSION_COLUMNS as $column => $type) {
            $count = $this->bucketFloat($metrics, $column, $bucket);

            if ($count === null) {
                continue;
            }

            $records[] = new ConversionRecord(
                rawActionType: $column,
                count: $count,
                actionType: $type,
                /*
                 * No value: X reports conversion counts in these groups and not their
                 * worth. A value assembled here would be ours, not the platform's.
                 */
                valueMinor: null,
                currency: null,
            );
        }

        return $records;
    }

    /**
     * A card's components, keyed by their `type`.
     *
     * A card holds at most one component of each type in X's own examples, so a map is
     * the readable shape; the untouched array is kept in metadata either way.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, array<string, mixed>>
     */
    private function components(array $raw): array
    {
        $components = [];

        foreach ($this->list($raw, 'components') as $component) {
            if (! is_array($component)) {
                continue;
            }

            /** @var array<string, mixed> $component */
            $type = $component['type'] ?? null;

            if (is_string($type) && $type !== '') {
                $components[strtoupper($type)] = $component;
            }
        }

        return $components;
    }

    /**
     * The media a card shows, in the order it shows them.
     *
     * Keys, not URLs: a `media_key` identifies a media library entry, and resolving it
     * to a URL is a separate request.
     *
     * @param  array<string, array<string, mixed>>  $components
     * @return list<CreativeAssetRecord>
     */
    private function assets(array $components): array
    {
        $single = $components['MEDIA'] ?? [];
        $key = $this->string($single, 'media_key');

        if ($key !== null) {
            return [new CreativeAssetRecord(kind: 'media', externalId: $key)];
        }

        $assets = [];
        $position = 0;

        foreach ($this->list($components['SWIPEABLE_MEDIA'] ?? [], 'media_keys') as $mediaKey) {
            if (is_string($mediaKey) && $mediaKey !== '') {
                $assets[] = new CreativeAssetRecord(
                    kind: 'media',
                    externalId: $mediaKey,
                    position: $position++,
                );
            }
        }

        return $assets;
    }

    /**
     * A value out of one of X's typed wrappers.
     *
     * `{"type": "ENUM", "value": "INSTALL"}` in X's own example. A plain string is
     * accepted too, so a simpler shape elsewhere in the API still reads.
     *
     * @param  array<string, mixed>  $component
     */
    private function wrapped(array $component, string $key): ?string
    {
        $value = $component[$key] ?? null;

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->string($value, 'value');
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A component's destination URL, where it has one.
     *
     * An app destination carries store identifiers rather than a URL, and nothing is
     * reported for those: a store id is not a link, and the component stays in
     * metadata whole.
     *
     * @param  array<string, mixed>  $component
     */
    private function destination(array $component): ?string
    {
        $destination = $component['destination'] ?? null;

        if (! is_array($destination)) {
            return null;
        }

        /** @var array<string, mixed> $destination */
        return $this->string($destination, 'url');
    }

    /**
     * How many buckets this entity's series holds.
     *
     * Taken from the longest metric array rather than assumed: a metric with no data
     * comes back as `null` instead of an array of zeros, so the first metric may be
     * shorter than the series.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function bucketCount(array $metrics): int
    {
        $length = 0;

        foreach ($metrics as $value) {
            if (is_array($value)) {
                $length = max($length, count($value));
            }
        }

        return $length;
    }

    /**
     * One bucket of one metric, or null when it was not reported.
     *
     * X returns `null` for a metric with no data and documents that null and zero are
     * equivalent *for it*. They are not equivalent here: an aggregate has to be able
     * to say "not reported" rather than claim a measured zero, so a null stays a null
     * and only a figure X actually sent becomes a number.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function bucketValue(array $metrics, string $name, int $bucket): mixed
    {
        $series = $metrics[$name] ?? null;

        if (! is_array($series)) {
            return null;
        }

        return $series[$bucket] ?? null;
    }

    /** @param array<string, mixed> $metrics */
    private function bucketInt(array $metrics, string $name, int $bucket): ?int
    {
        $value = $this->bucketValue($metrics, $name, $bucket);

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return is_string($value) && is_numeric($value) ? (int) round((float) $value) : null;
    }

    /** @param array<string, mixed> $metrics */
    private function bucketFloat(array $metrics, string $name, int $bucket): ?float
    {
        $value = $this->bucketValue($metrics, $name, $bucket);

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * Metrics whose names this mapper has not confirmed, kept as sent.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function unverifiedMetrics(array $metrics): array
    {
        return array_diff_key($metrics, array_flip(self::VERIFIED_METRICS));
    }

    /**
     * `reasons_not_servable`, upper-cased.
     *
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private function reasons(array $raw): array
    {
        $codes = [];

        foreach ($this->list($raw, 'reasons_not_servable') as $reason) {
            if (is_string($reason)) {
                $codes[] = strtoupper($reason);
            }
        }

        return $codes;
    }

    /** @param array<string, mixed> $raw */
    private function effectiveStatus(array $raw): ?string
    {
        $effective = $this->string($raw, 'effective_status');

        if ($effective !== null) {
            return $effective;
        }

        $reasons = $this->reasons($raw);

        return $reasons === [] ? null : implode(',', $reasons);
    }

    /** @param array<string, mixed> $raw */
    private function tweetLabel(array $raw): string
    {
        $tweet = $this->string($raw, 'tweet_id');

        return $tweet === null ? 'Promoted tweet' : 'Tweet '.$tweet;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<mixed>
     */
    private function list(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function object(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $raw */
    private function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $raw */
    private function int(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return is_string($value) && is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
