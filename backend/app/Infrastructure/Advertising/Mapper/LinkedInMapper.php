<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Mapper;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\ConversionRecord;
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
 * LinkedIn Marketing API → digitalSmart.
 *
 * Field names, enum values and response shapes were read from LinkedIn's published
 * reference. Five things are LinkedIn's own, and the first is a trap:
 *
 *   - **A LinkedIn "campaign" is an ad set.** The hierarchy is
 *     account → campaignGroup → campaign → creative, and the *campaign* is what holds
 *     targeting, a bid, a budget and an objective. So a campaign **group** maps to our
 *     campaign and a campaign maps to our ad group. Anyone comparing our screen with
 *     Campaign Manager has to know that, which is why it is said here, in
 *     docs/INTEGRATIONS.md and in the fixture README rather than left to be deduced.
 *   - **Money is a decimal string inside an object** — `{"amount": "60000.00",
 *     "currencyCode": "USD"}` — not micros and not a bare string. Analytics money is a
 *     bare decimal string that may carry *more than two decimals* (`"19.91833"`), so
 *     it is rounded at the minor unit rather than truncated.
 *   - **Schedules are epoch milliseconds**, and `runSchedule.end` is exclusive.
 *   - **Ids are URNs in some places and longs in others.** A campaign group's `id` is
 *     `512358882`; a creative's is `urn:li:sponsoredCreative:119962155`; parents are
 *     always URNs. Everything is reduced to the trailing id, because that is what a
 *     later request and a stored row both need.
 *   - **`servingStatuses` is the authority on delivery.** `status` is what the
 *     advertiser set; `servingStatuses` is service-controlled, and `RUNNABLE` is its
 *     only serving value — every other documented value is a hold.
 */
final class LinkedInMapper
{
    /**
     * Metric names confirmed against LinkedIn's reporting schema.
     *
     * Anything else is carried into metadata rather than guessed at.
     */
    private const VERIFIED_METRICS = [
        'impressions', 'clicks', 'costInLocalCurrency', 'costInUsd',
        'conversionValueInLocalCurrency', 'externalWebsiteConversions',
        'externalWebsitePostClickConversions', 'externalWebsitePostViewConversions',
        'oneClickLeads', 'oneClickLeadFormOpens', 'qualifiedLeads',
        'validWorkEmailLeads', 'talentLeads', 'landingPageClicks',
        'approximateMemberReach', 'totalEngagements', 'reactions', 'likes',
        'comments', 'shares', 'follows', 'companyPageClicks', 'textUrlClicks',
        'otherEngagements', 'videoViews', 'videoStarts', 'videoCompletions',
        'videoFirstQuartileCompletions', 'videoMidpointCompletions',
        'videoThirdQuartileCompletions', 'videoWatchTime', 'averageVideoWatchTime',
        'cardImpressions', 'cardClicks', 'averageDwellTime', 'audiencePenetration',
        'opens', 'sends',
        // Not metrics, but they arrive in the same object.
        'dateRange', 'pivotValues',
    ];

    /**
     * Conversion columns mapped to our vocabulary.
     *
     * LinkedIn reports conversions as named columns rather than an action array.
     * `externalWebsiteConversions` is the only *total* it publishes, so it is the
     * headline; the post-click and post-view figures are its two halves and are kept
     * in metadata rather than counted again as rows.
     */
    private const CONVERSION_COLUMNS = [
        'externalWebsiteConversions' => ConversionActionType::Other,
        'oneClickLeads' => ConversionActionType::Lead,
        'qualifiedLeads' => ConversionActionType::Lead,
        'validWorkEmailLeads' => ConversionActionType::Lead,
        'talentLeads' => ConversionActionType::Lead,
    ];

    /**
     * A campaign *group*, which is what our campaign level means on LinkedIn.
     *
     * @param  array<string, mixed>  $raw  an `adCampaignGroups` element
     */
    public function campaign(array $raw, string $currency): CampaignRecord
    {
        $total = $this->object($raw, 'totalBudget');
        $groupCurrency = $this->string($total, 'currencyCode') ?? $currency;
        $daily = $this->decimal($raw, 'dailyBudget');
        $totalAmount = $this->decimal($total, 'amount');
        $schedule = $this->object($raw, 'runSchedule');
        $optimization = $this->object($raw, 'budgetOptimization');
        $objective = $this->string($raw, 'objectiveType');

        /*
         * A group's daily budget only exists under dynamic budget optimisation, where
         * it is shared across the campaigns beneath it; otherwise the total is the
         * group's own limit and the campaigns pace themselves.
         */
        [$budgetMode, $budgetMinor] = match (true) {
            $daily !== null => [BudgetMode::Daily, $this->minor($daily, $groupCurrency)],
            $totalAmount !== null => [BudgetMode::Lifetime, $this->minor($totalAmount, $groupCurrency)],
            default => [BudgetMode::Inherited, null],
        };

        return new CampaignRecord(
            externalId: $this->id($raw),
            name: $this->string($raw, 'name') ?? 'Untitled campaign group',
            status: $this->status($raw),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->servingStatus($raw),
            objective: $this->objective($objective),
            rawObjective: $objective,
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            spendCapMinor: $totalAmount === null ? null : $this->minor($totalAmount, $groupCurrency),
            currency: $groupCurrency,
            // AUCTION or RESERVATION, which is as close as LinkedIn comes to Meta's
            // buying type.
            buyingType: $this->string($raw, 'buyingType'),
            bidStrategy: $this->string($optimization, 'bidStrategy'),
            startsAt: $this->millis($schedule, 'start'),
            endsAt: $this->millis($schedule, 'end'),
            providerCreatedAt: $this->audit($raw, 'created'),
            providerUpdatedAt: $this->audit($raw, 'lastModified'),
            metadata: array_filter([
                'account' => $this->string($raw, 'account'),
                'serving_statuses' => $raw['servingStatuses'] ?? null,
                'allowed_campaign_types' => $raw['allowedCampaignTypes'] ?? null,
                'budget_optimization_strategy' => $this->string($optimization, 'budgetOptimizationStrategy'),
                /*
                 * True for the group LinkedIn creates automatically with every account,
                 * whose fields an enterprise account cannot change through the API. An
                 * operator seeing "cannot edit" needs to know why.
                 */
                'backfilled' => $raw['backfilled'] ?? null,
                'test_account' => $raw['test'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * A LinkedIn *campaign*, which is an ad set.
     *
     * @param  array<string, mixed>  $raw  an `adCampaigns` element
     */
    public function adGroup(array $raw, string $currency): AdGroupRecord
    {
        $daily = $this->object($raw, 'dailyBudget');
        $total = $this->object($raw, 'totalBudget');
        $unitCost = $this->object($raw, 'unitCost');
        $campaignCurrency = $this->string($daily, 'currencyCode')
            ?? $this->string($total, 'currencyCode')
            ?? $this->string($unitCost, 'currencyCode')
            ?? $currency;
        $dailyAmount = $this->decimal($daily, 'amount');
        $totalAmount = $this->decimal($total, 'amount');
        $bid = $this->decimal($unitCost, 'amount');
        $schedule = $this->object($raw, 'runSchedule');

        [$budgetMode, $budgetMinor] = match (true) {
            $dailyAmount !== null => [BudgetMode::Daily, $this->minor($dailyAmount, $campaignCurrency)],
            $totalAmount !== null => [BudgetMode::Lifetime, $this->minor($totalAmount, $campaignCurrency)],
            default => [BudgetMode::Inherited, null],
        };

        return new AdGroupRecord(
            externalId: $this->id($raw),
            campaignExternalId: $this->urnId($this->string($raw, 'campaignGroup')),
            name: $this->string($raw, 'name') ?? 'Untitled campaign',
            status: $this->status($raw),
            rawStatus: $this->string($raw, 'status'),
            rawEffectiveStatus: $this->servingStatus($raw),
            optimizationGoal: $this->string($raw, 'optimizationTargetType'),
            // CPM, CPC or CPV: what LinkedIn charges for, which is exactly a billing
            // event.
            billingEvent: $this->string($raw, 'costType'),
            /*
             * Zero is LinkedIn's documented default under auto-bidding and means "no
             * bid set", not a bid of nothing — so it is reported as absent.
             */
            bidAmountMinor: $bid === null || $bid === '0' || (float) $bid === 0.0
                ? null
                : $this->minor($bid, $campaignCurrency),
            budgetMode: $budgetMode,
            budgetMinor: $budgetMinor,
            currency: $campaignCurrency,
            startsAt: $this->millis($schedule, 'start'),
            endsAt: $this->millis($schedule, 'end'),
            providerCreatedAt: $this->audit($raw, 'created'),
            providerUpdatedAt: $this->audit($raw, 'lastModified'),
            /*
             * LinkedIn's targeting *is* on the campaign, unlike X's — a boolean tree of
             * include/exclude facets. Stored as sent: reshaping someone's audience
             * definition into a flatter form loses the operators that define it.
             */
            targeting: $this->object($raw, 'targetingCriteria'),
            metadata: array_filter([
                'objective_type' => $this->string($raw, 'objectiveType'),
                // The ad format lives here, not on the creative.
                'format' => $this->string($raw, 'format'),
                'type' => $this->string($raw, 'type'),
                'pacing_strategy' => $this->string($raw, 'pacingStrategy'),
                'creative_selection' => $this->string($raw, 'creativeSelection'),
                'cost_type' => $this->string($raw, 'costType'),
                'target_cpa' => $this->decimal($this->object($raw, 'targetCpa'), 'amount'),
                'locale' => $raw['locale'] ?? null,
                'audience_expansion_enabled' => $raw['audienceExpansionEnabled'] ?? null,
                'offsite_delivery_enabled' => $raw['offsiteDeliveryEnabled'] ?? null,
                'serving_statuses' => $raw['servingStatuses'] ?? null,
                'political_intent' => $this->string($raw, 'politicalIntent'),
                'associated_entity' => $this->string($raw, 'associatedEntity'),
                'version_tag' => $this->string($raw, 'versionTag'),
                'test_account' => $raw['test'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * A creative, which on LinkedIn is the ad.
     *
     * @param  array<string, mixed>  $raw  a `creatives` element
     */
    public function ad(array $raw): AdRecord
    {
        $review = $this->object($raw, 'review');
        $reviewStatus = $this->string($review, 'status');
        $id = $this->urnId($this->string($raw, 'id'));

        return new AdRecord(
            externalId: $id,
            adGroupExternalId: $this->urnId($this->string($raw, 'campaign')),
            /*
             * No campaign-group reference on a creative: the hierarchy is reached
             * through the campaign. Left empty rather than guessed — the store resolves
             * it from the campaign it has already synced.
             */
            campaignExternalId: '',
            name: $this->string($raw, 'name') ?? $this->contentLabel($raw),
            status: $this->adStatus($raw, $reviewStatus),
            rawStatus: $this->string($raw, 'intendedStatus'),
            rawEffectiveStatus: $reviewStatus ?? $this->holdReasons($raw),
            // The creative *is* the ad here, so the two ids are the same one.
            creativeExternalId: $id,
            reviewFeedback: $this->rejectionReasons($review),
            providerCreatedAt: $this->millis($raw, 'createdAt'),
            providerUpdatedAt: $this->millis($raw, 'lastModifiedAt'),
            metadata: array_filter([
                'content' => $raw['content'] ?? null,
                'is_serving' => $raw['isServing'] ?? null,
                'serving_hold_reasons' => $raw['servingHoldReasons'] ?? null,
                'review_status' => $reviewStatus,
                'test_creative' => $raw['isTest'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * The same creative, as the creative record.
     *
     * LinkedIn has no separate creative resource: a creative references a post, and
     * the words, image and link all live on that post — a different API, and a
     * different request this sync does not make. So the only things reported here are
     * the ones the creative object actually carries.
     *
     * @param  array<string, mixed>  $raw  a `creatives` element
     */
    public function creative(array $raw): CreativeRecord
    {
        $content = $this->object($raw, 'content');

        return new CreativeRecord(
            externalId: $this->urnId($this->string($raw, 'id')),
            name: $this->string($raw, 'name') ?? $this->contentLabel($raw),
            /*
             * Unknown, and necessarily: the ad format is a property of the *campaign*
             * on LinkedIn, and the creative only points at a post whose medium would
             * take another call to learn. The campaign's `format` is kept on the ad
             * group, where it was actually reported.
             */
            format: CreativeFormat::Unknown,
            title: null,
            body: null,
            callToAction: null,
            destinationUrl: null,
            thumbnailUrl: null,
            previewUrl: null,
            assets: [],
            metadata: array_filter([
                // The post being sponsored, e.g. urn:li:share:6778045555198214144.
                'content_reference' => $this->string($content, 'reference'),
                'review_status' => $this->string($this->object($raw, 'review'), 'status'),
                'intended_status' => $this->string($raw, 'intendedStatus'),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * One analytics element: one entity, one day.
     *
     * The entity comes from `pivotValues`, which is an array because a request may
     * pivot on up to three things. This sync asks for one, so the first value is the
     * entity — and a row with more than one pivot value is a different question than
     * the one that was asked.
     *
     * @param  array<string, mixed>  $raw
     */
    public function insight(array $raw, AdEntityType $level, string $currency): InsightRecord
    {
        $cost = $this->decimalString($raw, 'costInLocalCurrency');
        $value = $this->decimalString($raw, 'conversionValueInLocalCurrency');

        return new InsightRecord(
            entityType: $level,
            externalEntityId: $this->pivotId($raw),
            date: $this->rangeStart($raw),
            currency: $currency,
            /*
             * A decimal string that may carry more than two decimals — "19.91833" is
             * LinkedIn's own example. Rounded half-up at the minor unit, which is what
             * their billing does; truncating would lose a halala per row.
             */
            spendMinor: $cost === null ? null : Money::fromDecimalString($cost, $currency)->minorUnits,
            /*
             * LinkedIn *does* report conversion value, unlike X — one total rather than
             * a figure per conversion type, which is why it lands here rather than on a
             * breakdown row.
             */
            revenueMinor: $value === null ? null : Money::fromDecimalString($value, $currency)->minorUnits,
            impressions: $this->int($raw, 'impressions'),
            /*
             * Deduplicated member reach, and only available for ranges of 92 days or
             * less and non-demographic pivots. Absent means not reported, which is why
             * nothing is derived from impressions and frequency.
             */
            reach: $this->int($raw, 'approximateMemberReach'),
            clicks: $this->int($raw, 'clicks'),
            // Clicks that reached the advertiser's page, which is LinkedIn's own
            // metric rather than a share of `clicks`.
            linkClicks: $this->int($raw, 'landingPageClicks'),
            videoViews: $this->int($raw, 'videoViews'),
            videoCompletions: $this->int($raw, 'videoCompletions'),
            // "The count of all user interactions with the ad unit" — a figure
            // LinkedIn defines, so it is reported rather than assembled.
            engagements: $this->int($raw, 'totalEngagements'),
            conversions: $this->float($raw, 'externalWebsiteConversions'),
            conversionBreakdown: $this->conversions($raw),
            metadata: array_filter([
                'cost_in_usd' => $this->decimalString($raw, 'costInUsd'),
                /*
                 * The two halves of `externalWebsiteConversions`. Kept here rather than
                 * as breakdown rows, which would count the same conversion twice.
                 */
                'external_website_post_click_conversions' => $this->int($raw, 'externalWebsitePostClickConversions'),
                'external_website_post_view_conversions' => $this->int($raw, 'externalWebsitePostViewConversions'),
                'one_click_lead_form_opens' => $this->int($raw, 'oneClickLeadFormOpens'),
                'reactions' => $this->int($raw, 'reactions'),
                'comments' => $this->int($raw, 'comments'),
                'shares' => $this->int($raw, 'shares'),
                'follows' => $this->int($raw, 'follows'),
                'company_page_clicks' => $this->int($raw, 'companyPageClicks'),
                'video_starts' => $this->int($raw, 'videoStarts'),
                'video_first_quartile_completions' => $this->int($raw, 'videoFirstQuartileCompletions'),
                'video_midpoint_completions' => $this->int($raw, 'videoMidpointCompletions'),
                'video_third_quartile_completions' => $this->int($raw, 'videoThirdQuartileCompletions'),
                'video_watch_time' => $this->int($raw, 'videoWatchTime'),
                'card_impressions' => $this->int($raw, 'cardImpressions'),
                'card_clicks' => $this->int($raw, 'cardClicks'),
                'average_dwell_time' => $this->int($raw, 'averageDwellTime'),
                'audience_penetration' => $this->float($raw, 'audiencePenetration'),
                'unmapped_metrics' => $this->unverifiedMetrics($raw),
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }

    /**
     * `status` refined by `servingStatuses`.
     *
     * `status` is what the advertiser set. `servingStatuses` is service-controlled and
     * `RUNNABLE` is its only serving value, so anything else there means the entity is
     * held — whatever the switch says. The specific hold still reaches the interface
     * as the raw effective status, because "not delivering" and "the parent account is
     * on billing hold" call for different actions.
     *
     * @param  array<string, mixed>  $raw
     */
    public function status(array $raw): EntityStatus
    {
        $status = strtoupper($this->string($raw, 'status') ?? '');

        $fromStatus = match ($status) {
            'PAUSED' => EntityStatus::Paused,
            'ARCHIVED' => EntityStatus::Archived,
            'COMPLETED' => EntityStatus::Completed,
            /*
             * Removed rather than completed: LinkedIn's CANCELED is permanent and not
             * re-activatable, and "completed" would say it ran its course. REMOVED and
             * PENDING_DELETION are deletions that stay fetchable because performance
             * data exists for them.
             */
            'CANCELED', 'CANCELLED', 'REMOVED', 'PENDING_DELETION' => EntityStatus::Removed,
            'DRAFT' => EntityStatus::NotDelivering,
            default => null,
        };

        if ($fromStatus instanceof EntityStatus) {
            return $fromStatus;
        }

        if ($status !== 'ACTIVE') {
            return EntityStatus::Unknown;
        }

        return $this->isRunnable($raw) ? EntityStatus::Active : EntityStatus::NotDelivering;
    }

    /**
     * A creative's state, from its own switch, its review and its holds.
     *
     * A rejected creative cannot run however its `intendedStatus` reads, and a
     * creative awaiting review has not been refused — it is simply not live yet.
     *
     * @param  array<string, mixed>  $raw
     */
    public function adStatus(array $raw, ?string $reviewStatus): EntityStatus
    {
        $fromReview = match (strtoupper((string) $reviewStatus)) {
            'REJECTED' => EntityStatus::Rejected,
            'PENDING' => EntityStatus::InReview,
            default => null,
        };

        if ($fromReview instanceof EntityStatus) {
            return $fromReview;
        }

        $intended = strtoupper($this->string($raw, 'intendedStatus') ?? '');

        $fromIntent = match ($intended) {
            'PAUSED' => EntityStatus::Paused,
            'ARCHIVED' => EntityStatus::Archived,
            'DRAFT' => EntityStatus::NotDelivering,
            'CANCELED', 'CANCELLED', 'REMOVED', 'PENDING_DELETION' => EntityStatus::Removed,
            default => null,
        };

        if ($fromIntent instanceof EntityStatus) {
            return $fromIntent;
        }

        if ($intended !== 'ACTIVE') {
            return EntityStatus::Unknown;
        }

        /*
         * `isServing` is the provider's own answer, and `servingHoldReasons` is absent
         * entirely when a creative is serving. An active creative that is held is not
         * delivering, and saying "active" would contradict the field.
         */
        return ($raw['isServing'] ?? null) === false || $this->holdReasons($raw) !== null
            ? EntityStatus::NotDelivering
            : EntityStatus::Active;
    }

    /** LinkedIn's objective enum, which appears on groups and campaigns alike. */
    public function objective(?string $raw): CampaignObjective
    {
        return match (strtoupper((string) $raw)) {
            'BRAND_AWARENESS' => CampaignObjective::Awareness,
            'ENGAGEMENT' => CampaignObjective::Engagement,
            'VIDEO_VIEWS', 'VIDEO_VIEW' => CampaignObjective::VideoViews,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'WEBSITE_VISITS', 'WEBSITE_VISIT', 'WEBSITE_TRAFFIC' => CampaignObjective::Traffic,
            'WEBSITE_CONVERSIONS', 'WEBSITE_CONVERSION' => CampaignObjective::Sales,
            /*
             * Hiring, which has no equivalent in a vocabulary built for commerce.
             * Filed as other rather than squeezed into "leads": a job applicant is not
             * a sales lead, and a chart labelling it as one would mislead.
             */
            'JOB_APPLICANTS', 'JOB_APPLICANT' => CampaignObjective::Other,
            default => CampaignObjective::Other,
        };
    }

    /** LinkedIn's conversion column names onto our vocabulary. */
    public function conversionType(string $column): ConversionActionType
    {
        return self::CONVERSION_COLUMNS[$column] ?? ConversionActionType::Other;
    }

    /**
     * One record per conversion column that reported a figure.
     *
     * None carries a value: LinkedIn reports `conversionValueInLocalCurrency` as a
     * single total for the row, not per conversion type, so attaching it to one of
     * these would be inventing an attribution it never made.
     *
     * @param  array<string, mixed>  $raw
     * @return list<ConversionRecord>
     */
    private function conversions(array $raw): array
    {
        $records = [];

        foreach (self::CONVERSION_COLUMNS as $column => $type) {
            $count = $this->float($raw, $column);

            if ($count === null) {
                continue;
            }

            $records[] = new ConversionRecord(
                rawActionType: $column,
                count: $count,
                actionType: $type,
                valueMinor: null,
                currency: null,
            );
        }

        return $records;
    }

    /**
     * Whether `servingStatuses` says this entity can serve at all.
     *
     * @param  array<string, mixed>  $raw
     */
    private function isRunnable(array $raw): bool
    {
        $statuses = $raw['servingStatuses'] ?? null;

        // Absent says nothing either way, so the advertiser's own switch stands.
        if (! is_array($statuses) || $statuses === []) {
            return true;
        }

        foreach ($statuses as $status) {
            if (is_string($status) && strtoupper($status) !== 'RUNNABLE') {
                return false;
            }
        }

        return true;
    }

    /**
     * The serving holds as one string, for the raw effective status.
     *
     * @param  array<string, mixed>  $raw
     */
    private function servingStatus(array $raw): ?string
    {
        $codes = [];

        foreach (is_array($raw['servingStatuses'] ?? null) ? $raw['servingStatuses'] : [] as $status) {
            if (is_string($status) && strtoupper($status) !== 'RUNNABLE') {
                $codes[] = strtoupper($status);
            }
        }

        return $codes === [] ? null : implode(',', $codes);
    }

    /** @param array<string, mixed> $raw */
    private function holdReasons(array $raw): ?string
    {
        $codes = [];

        foreach (is_array($raw['servingHoldReasons'] ?? null) ? $raw['servingHoldReasons'] : [] as $reason) {
            if (is_string($reason)) {
                $codes[] = strtoupper($reason);
            }
        }

        return $codes === [] ? null : implode(',', $codes);
    }

    /** @param array<string, mixed> $review */
    private function rejectionReasons(array $review): ?string
    {
        $reasons = [];

        foreach (is_array($review['rejectionReasons'] ?? null) ? $review['rejectionReasons'] : [] as $reason) {
            if (is_string($reason)) {
                $reasons[] = $reason;
            }
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * A label for a creative that has no name.
     *
     * `name` is optional on LinkedIn, and most creatives made in Campaign Manager have
     * none. The post it sponsors is the next most recognisable thing, and an operator
     * can at least match that against their content calendar.
     *
     * @param  array<string, mixed>  $raw
     */
    private function contentLabel(array $raw): string
    {
        $reference = $this->string($this->object($raw, 'content'), 'reference');

        return $reference === null ? 'Creative' : 'Sponsored '.$this->urnType($reference).' '.$this->urnId($reference);
    }

    /**
     * The entity this analytics row is about.
     *
     * @param  array<string, mixed>  $raw
     */
    private function pivotId(array $raw): string
    {
        $values = $raw['pivotValues'] ?? null;
        $first = is_array($values) ? ($values[0] ?? null) : null;

        return is_string($first) ? $this->urnId($first) : '';
    }

    /**
     * The day an analytics row covers.
     *
     * `dateRange.start` is three integers rather than a string, and at DAILY
     * granularity start and end are the same day.
     *
     * @param  array<string, mixed>  $raw
     */
    private function rangeStart(array $raw): DateTimeImmutable
    {
        $start = $this->object($this->object($raw, 'dateRange'), 'start');
        $year = $this->int($start, 'year');
        $month = $this->int($start, 'month');
        $day = $this->int($start, 'day');

        if ($year === null || $month === null || $day === null) {
            return new DateTimeImmutable('today');
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * An id, whether LinkedIn sent a long or a URN.
     *
     * @param  array<string, mixed>  $raw
     */
    private function id(array $raw): string
    {
        return $this->urnId($this->string($raw, 'id'));
    }

    /** The trailing id of a URN, or the value unchanged when it is not one. */
    private function urnId(?string $urn): string
    {
        if ($urn === null) {
            return '';
        }

        $position = strrpos($urn, ':');

        return $position === false ? $urn : substr($urn, $position + 1);
    }

    /** The entity kind named in a URN, e.g. `share` in `urn:li:share:123`. */
    private function urnType(string $urn): string
    {
        $parts = explode(':', $urn);

        return count($parts) >= 3 ? $parts[count($parts) - 2] : 'content';
    }

    private function minor(string $amount, string $currency): int
    {
        return Money::fromDecimalString($amount, $currency)->minorUnits;
    }

    /**
     * A money amount, which LinkedIn sends as a decimal string.
     *
     * @param  array<string, mixed>  $raw
     */
    private function decimal(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if (is_string($value)) {
            return is_numeric($value) ? $value : null;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $raw */
    private function decimalString(array $raw, string $key): ?string
    {
        return $this->decimal($raw, $key);
    }

    /**
     * An epoch-millisecond timestamp.
     *
     * @param  array<string, mixed>  $raw
     */
    private function millis(array $raw, string $key): ?DateTimeImmutable
    {
        $value = $raw[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $millis = (int) $value;

        return $millis === 0 ? null : (new DateTimeImmutable('@'.intdiv($millis, 1000)));
    }

    /**
     * A `changeAuditStamps` time.
     *
     * Campaign groups and campaigns report their timestamps here, while creatives use
     * plain `createdAt`/`lastModifiedAt`. Two shapes in one API, both read as sent.
     *
     * @param  array<string, mixed>  $raw
     */
    private function audit(array $raw, string $which): ?DateTimeImmutable
    {
        return $this->millis($this->object($this->object($raw, 'changeAuditStamps'), $which), 'time');
    }

    /**
     * Metrics whose names this mapper has not confirmed, kept as sent.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function unverifiedMetrics(array $raw): array
    {
        return array_diff_key($raw, array_flip(self::VERIFIED_METRICS));
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

    /** @param array<string, mixed> $raw */
    private function float(array $raw, string $key): ?float
    {
        $value = $raw[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }
}
