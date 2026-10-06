<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * What an adapter can do against a provider.
 *
 * These flags drive the interface: a control that would call an unsupported
 * capability is not rendered, and the use case refuses it server-side. We never
 * implement a write path a provider does not have, and never simulate one.
 */
enum ProviderCapability: string
{
    case AccountDiscovery = 'account_discovery';
    case CampaignDiscovery = 'campaign_discovery';
    case MetricsSync = 'metrics_sync';
    case CreativeRequirements = 'creative_requirements';
    case CampaignStatusMutation = 'campaign_status_mutation';
    case BudgetMutation = 'budget_mutation';
    case AudienceSync = 'audience_sync';
    case ConversionImport = 'conversion_import';

    /*
     * Measurement and commerce. Added with the registry rather than invented per
     * adapter, so "what can this provider do" has one answer.
     */
    case PropertyDiscovery = 'property_discovery';
    case EventStreamRead = 'event_stream_read';
    case TagInspection = 'tag_inspection';
    case OrderSync = 'order_sync';
    case ProductSync = 'product_sync';
    case CustomerSync = 'customer_sync';
    case Webhooks = 'webhooks';

    public function label(): string
    {
        return match ($this) {
            self::AccountDiscovery => 'Discover ad accounts',
            self::CampaignDiscovery => 'Read campaign structure',
            self::MetricsSync => 'Sync performance metrics',
            self::CreativeRequirements => 'Read creative specifications',
            self::CampaignStatusMutation => 'Change campaign status',
            self::BudgetMutation => 'Change budgets',
            self::AudienceSync => 'Sync audiences',
            self::ConversionImport => 'Import conversions',
            self::PropertyDiscovery => 'Discover properties and containers',
            self::EventStreamRead => 'Read events and reporting data',
            self::TagInspection => 'Inspect tags, triggers and variables',
            self::OrderSync => 'Sync orders',
            self::ProductSync => 'Sync products',
            self::CustomerSync => 'Sync customers',
            self::Webhooks => 'Receive webhooks',
        };
    }

    /**
     * The kind of provider this capability belongs to, where it belongs to one.
     *
     * **Null means the capability is not specific to a kind of provider.** Discovering
     * accounts, reading reporting figures and receiving webhooks are things an ad
     * platform, an analytics property and a store all do, and forcing each into one
     * category would make the registry's own consistency check meaningless — which is the
     * check that stops a store claiming it can report campaign metrics.
     */
    public function category(): ?ProviderCategory
    {
        return match ($this) {
            self::CampaignDiscovery,
            self::CreativeRequirements,
            self::CampaignStatusMutation,
            self::BudgetMutation,
            self::AudienceSync,
            self::ConversionImport => ProviderCategory::Advertising,
            self::EventStreamRead => ProviderCategory::Analytics,
            self::TagInspection => ProviderCategory::TagManagement,
            self::OrderSync,
            self::ProductSync,
            self::CustomerSync => ProviderCategory::Commerce,
            /*
             * Shared. `MetricsSync` in particular: an ad platform reports its own
             * performance and an analytics property reports a site's, and both are
             * reporting reads.
             */
            self::AccountDiscovery,
            self::MetricsSync,
            self::PropertyDiscovery,
            self::Webhooks => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether exercising this capability changes something on the provider.
     *
     * Write capabilities are gated behind explicit approval: nothing in
     * digitalSmart silently mutates live ad spend.
     */
    public function isWrite(): bool
    {
        return match ($this) {
            self::CampaignStatusMutation,
            self::BudgetMutation,
            self::AudienceSync,
            self::ConversionImport => true,
            /*
             * Receiving a webhook is not a write: the provider pushes to us. Verifying
             * the signature and queueing the work is all that happens, and nothing
             * changes on their side.
             */
            default => false,
        };
    }
}
