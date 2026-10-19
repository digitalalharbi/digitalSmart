<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * The provider registry: every platform digitalSmart integrates with, and everything
 * known about it.
 *
 * **This is the single source of truth for provider vocabulary.** The name a platform is
 * called, what kind of thing it is, what its API can do, how a workspace authorises it,
 * how often it may be called and where its documentation lives are all answered here and
 * nowhere else. A page or a mapper that kept its own list would drift the day a provider
 * was added — which is exactly what happened to the platform labels in the first
 * allocation editor.
 *
 * Two rules this file is held to by its tests:
 *
 *  - **Capabilities describe the provider's API, not our progress.** `MetricsSync` on X
 *    means X publishes a reporting API, whether or not an adapter exists. What *we* have
 *    built is answered by the adapter registry, and the two are deliberately separate:
 *    conflating them would let shipping an adapter look like a provider gaining a feature.
 *  - **Nothing here is inferred from a provider's user interface.** Meta's web console can
 *    do things the Marketing API cannot. A capability appears only where the published API
 *    reference supports it.
 */
enum Provider: string
{
    // Advertising
    case Meta = 'meta';
    case GoogleAds = 'google_ads';
    case TikTok = 'tiktok';
    case Snapchat = 'snapchat';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case OpenAI = 'openai';

    // Measurement
    case GoogleAnalytics = 'google_analytics';
    case GoogleTagManager = 'google_tag_manager';

    // Commerce
    case Salla = 'salla';
    case Zid = 'zid';

    // CRM
    case HubSpot = 'hubspot';

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta Ads',
            self::GoogleAds => 'Google Ads',
            self::TikTok => 'TikTok Ads',
            self::Snapchat => 'Snapchat Ads',
            self::X => 'X Ads',
            self::LinkedIn => 'LinkedIn Ads',
            self::OpenAI => 'ChatGPT Ads',
            self::GoogleAnalytics => 'Google Analytics 4',
            self::GoogleTagManager => 'Google Tag Manager',
            self::Salla => 'Salla',
            self::Zid => 'Zid',
            self::HubSpot => 'HubSpot',
        };
    }

    /**
     * What kind of provider this is.
     *
     * Load-bearing rather than cosmetic: figures never total across categories, because
     * an ad platform's claimed revenue, an analytics property's recorded revenue and a
     * store's invoiced revenue are three different numbers.
     */
    public function category(): ProviderCategory
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat,
            self::X, self::LinkedIn, self::OpenAI => ProviderCategory::Advertising,
            self::GoogleAnalytics => ProviderCategory::Analytics,
            self::GoogleTagManager => ProviderCategory::TagManagement,
            self::Salla, self::Zid => ProviderCategory::Commerce,
            self::HubSpot => ProviderCategory::Crm,
        };
    }

    /** How reachable the provider's API is in principle, not whether we have connected it. */
    public function apiStatus(): ProviderApiStatus
    {
        return match ($this) {
            /*
             * Every advertising platform here gates its API behind an application: Meta's
             * Marketing API needs app review, Google Ads a production developer token, X
             * an access form, LinkedIn partner programme admission, TikTok and Snapchat a
             * developer app approval.
             */
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat,
            self::X, self::LinkedIn => ProviderApiStatus::RequiresApproval,
            /*
             * Google Analytics and Tag Manager need only a Google Cloud project and the
             * user's consent — no programme to be admitted to.
             */
            self::GoogleAnalytics, self::GoogleTagManager => ProviderApiStatus::GenerallyAvailable,
            // Both publish open partner programmes with self-service app registration.
            self::Salla, self::Zid => ProviderApiStatus::GenerallyAvailable,
            /*
             * A HubSpot app is created in a developer account with no review to pass for private use; a public
             * app needs listing approval only to be distributed. Read access to the CRM objects this product
             * wants is a matter of the installing account granting the scopes.
             */
            self::HubSpot => ProviderApiStatus::GenerallyAvailable,
            // No programme to apply to: see docs/INTEGRATIONS.md.
            self::OpenAI => ProviderApiStatus::Unavailable,
        };
    }

    /** How a workspace authorises this provider. */
    public function authMethod(): AuthMethod
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat, self::LinkedIn,
            self::GoogleAnalytics, self::GoogleTagManager, self::Salla, self::Zid,
            self::HubSpot => AuthMethod::OAuth2,
            // The only one: every X Ads request is signed with OAuth 1.0a.
            self::X => AuthMethod::OAuth1,
            // Unknown until the programme grants access; see docs/INTEGRATIONS.md.
            self::OpenAI => AuthMethod::Unknown,
        };
    }

    /**
     * What this product has read about writing to this provider, per operation — and so per level.
     *
     * Every `Documented` answer was read in the provider's own current documentation, at that operation's level;
     * {@see ProviderEntityWrites} holds the matrix and the endpoint and fields that were read. Support at one level
     * is never inferred from another. Anything not read there is `NotSupported` — an analytics property, a tag
     * container and a store are read integrations by design, and changing somebody's measurement configuration
     * from a reporting tool is a hazard rather than a feature.
     *
     * A capability flag in `capabilities()` says the product *models* the operation. This says whether it will
     * attempt it. The two are deliberately separate: the interface can show a control that explains why it is
     * disabled, which is more useful than a control that is absent.
     */
    public function writeSupport(WriteOperation $operation): WriteSupport
    {
        return ProviderEntityWrites::support($this, $operation);
    }

    /**
     * The endpoint and fields this product read, for an operation it will attempt.
     *
     * Kept in the code rather than only in documentation, because this is the sentence somebody needs when a
     * write behaves unexpectedly: what did we think the contract was. Null where no attempt is made.
     */
    public function writeReference(WriteOperation $operation): ?string
    {
        return $this->writeSupport($operation)->allowsAttempt() ? ProviderEntityWrites::reference($this, $operation) : null;
    }

    /**
     * What an adapter can do against this provider.
     *
     * A capability says the product *models* the operation; {@see writeSupport()} says whether it will attempt a
     * write. The two are separate so the interface can show a control that explains why it is disabled, which is
     * more useful than a control that is absent.
     *
     * @return list<ProviderCapability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat, self::LinkedIn, self::X => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::CampaignDiscovery,
                ProviderCapability::MetricsSync,
                ProviderCapability::CreativeRequirements,
                ProviderCapability::CampaignStatusMutation,
                ProviderCapability::BudgetMutation,
            ],

            /*
             * Read-only, and that is the whole point of the integration: digitalSmart
             * reads what a property measured. The Admin API can write, but nothing here
             * needs to — changing somebody's analytics configuration from a reporting
             * tool is not a feature, it is a hazard.
             */
            self::GoogleAnalytics => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::PropertyDiscovery,
                ProviderCapability::EventStreamRead,
                ProviderCapability::MetricsSync,
            ],

            /*
             * Inspection only. The API can create and publish tags; this product reads
             * them to say whether tracking is in place. Writing somebody's container from
             * a dashboard is how a measurement setup gets silently broken.
             */
            self::GoogleTagManager => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::PropertyDiscovery,
                ProviderCapability::TagInspection,
            ],

            self::Salla, self::Zid => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::OrderSync,
                ProviderCapability::ProductSync,
                ProviderCapability::CustomerSync,
                ProviderCapability::Webhooks,
            ],

            /*
             * Deliberately empty. The programme is not generally available and its
             * contract is unknown, so declaring anything here would be a guess the
             * interface would then present as fact.
             */
            /*
             * Read-only, and deliberately so. The CRM owns its own pipeline: this product reads what a deal's
             * stage *is* and never writes one back, because two systems both claiming to own a stage is how a
             * sales team loses a day's work to a sync. See `CrmStageMeaning` and docs/CRM.md.
             */
            self::HubSpot => [
                ProviderCapability::PipelineDiscovery,
                ProviderCapability::DealSync,
                ProviderCapability::ContactSync,
            ],

            self::OpenAI => [],
        };
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * The records this provider's API exposes that this product models.
     *
     * @return list<ProviderEntity>
     */
    public function supportedEntities(): array
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat, self::X, self::LinkedIn => [
                ProviderEntity::Account,
                ProviderEntity::Campaign,
                ProviderEntity::AdGroup,
                ProviderEntity::Ad,
                ProviderEntity::Creative,
                ProviderEntity::Insight,
                ProviderEntity::Conversion,
            ],
            self::GoogleAnalytics => [
                ProviderEntity::Account,
                ProviderEntity::Property,
                ProviderEntity::DataStream,
                ProviderEntity::Event,
            ],
            self::GoogleTagManager => [
                ProviderEntity::Account,
                ProviderEntity::Container,
                ProviderEntity::Workspace,
                ProviderEntity::Tag,
                ProviderEntity::Trigger,
                ProviderEntity::Variable,
                ProviderEntity::ContainerVersion,
            ],
            self::Salla, self::Zid => [
                ProviderEntity::Store,
                ProviderEntity::Order,
                ProviderEntity::OrderItem,
                ProviderEntity::Product,
                ProviderEntity::Variant,
                ProviderEntity::Customer,
                ProviderEntity::WebhookEvent,
            ],
            self::HubSpot => [
                ProviderEntity::Pipeline,
                ProviderEntity::PipelineStage,
                ProviderEntity::Deal,
                ProviderEntity::Contact,
            ],
            self::OpenAI => [],
        };
    }

    /**
     * What the provider publishes about how often it may be called.
     *
     * Null where nothing is published. An invented ceiling would be worse: a sync that
     * paces itself against a number nobody set is slow for a reason nobody can find.
     */
    public function rateLimit(): ?ProviderRateLimit
    {
        return match ($this) {
            self::X => new ProviderRateLimit(
                requests: 250,
                windowSeconds: 900,
                scope: 'user',
                note: 'Synchronous stats only. The asynchronous jobs endpoint allows 100 concurrent jobs per account.',
            ),
            self::LinkedIn => new ProviderRateLimit(
                scope: 'application',
                note: 'Throttled by data volume rather than request count: 45 million metric values per five minutes, '
                    .'where a value is one requested metric on one returned row.',
            ),
            self::GoogleTagManager => new ProviderRateLimit(
                requests: 25,
                windowSeconds: 100,
                scope: 'project',
                dailyRequests: 10_000,
                note: 'Published as 0.25 queries per second on a 100-second sliding window. '
                    .'Per *project*, so every workspace on one deployment shares it. Exceeding it answers 403, not 429.',
            ),
            self::HubSpot => new ProviderRateLimit(
                requests: 190,
                windowSeconds: 10,
                scope: 'app',
                dailyRequests: 625_000,
                note: 'Published per app per 10 seconds, with the daily figure depending on the installing '
                    .'account\'s tier — 625,000 on Professional and 1,000,000 on Enterprise, so the lower one '
                    .'is recorded here. Exceeding it answers 429, and every response carries '
                    .'X-HubSpot-RateLimit-Remaining and X-HubSpot-RateLimit-Interval-Milliseconds; no '
                    .'Retry-After is documented for a 429. Read 2026-10-08.',
            ),

            // Not published by these providers. Backed off on their own throttling instead.
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat,
            self::GoogleAnalytics, self::Salla, self::Zid => ProviderRateLimit::undocumented(),
            self::OpenAI => null,
        };
    }

    /** Where the contract this integration was written against is published. */
    public function documentationUrl(): ?string
    {
        return match ($this) {
            self::Meta => 'https://developers.facebook.com/docs/marketing-apis',
            self::GoogleAds => 'https://developers.google.com/google-ads/api/docs/start',
            self::TikTok => 'https://business-api.tiktok.com/portal/docs',
            self::Snapchat => 'https://developers.snap.com/api/marketing-api/Ads-API/introduction',
            self::X => 'https://docs.x.com/x-ads-api/introduction',
            self::LinkedIn => 'https://learn.microsoft.com/en-us/linkedin/marketing/overview',
            self::GoogleAnalytics => 'https://developers.google.com/analytics/devguides/config/admin/v1',
            self::GoogleTagManager => 'https://developers.google.com/tag-platform/tag-manager/api/v2',
            self::Salla => 'https://docs.salla.dev/',
            self::Zid => 'https://docs.zid.sa/',
            self::HubSpot => 'https://developers.hubspot.com/docs/api-reference/crm-deals-v3/guide',
            // Nothing to link to yet.
            self::OpenAI => null,
        };
    }

    /**
     * Credential keys the adapter needs before it can attempt a live handshake.
     *
     * Key *names*, never values. A connection is incomplete until every one of these is
     * present, which is what makes "configured" a fact rather than an intention.
     *
     * @return list<string>
     */
    public function requiredCredentialKeys(): array
    {
        return match ($this) {
            self::Meta => ['client_id', 'client_secret'],
            self::GoogleAds => ['client_id', 'client_secret', 'developer_token'],
            self::TikTok => ['app_id', 'app_secret'],
            self::Snapchat => ['client_id', 'client_secret'],
            self::X => ['consumer_key', 'consumer_secret'],
            self::LinkedIn => ['client_id', 'client_secret'],
            /*
             * Both Google APIs are reached with one OAuth client; the scopes differ, not
             * the credentials. Analytics needs `analytics.readonly`, Tag Manager its own
             * `tagmanager.readonly` — recorded in docs/INTEGRATIONS.md, where a scope
             * change is a documentation change rather than a schema one.
             */
            self::GoogleAnalytics, self::GoogleTagManager => ['client_id', 'client_secret'],
            self::Salla, self::Zid => ['client_id', 'client_secret'],
            /*
             * A HubSpot app's OAuth pair. A private-app token would be one value instead, and is deliberately not
             * modelled: a token pasted into a form is a credential nobody can rotate without a person, and this
             * product's other integrations all authorise through the account that owns the data.
             */
            self::HubSpot => ['client_id', 'client_secret'],
            self::OpenAI => ['api_key'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Providers of one kind.
     *
     * @return list<self>
     */
    public static function inCategory(ProviderCategory $category): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->category() === $category,
        ));
    }
}
