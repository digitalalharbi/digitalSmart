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
        };
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
            default => false,
        };
    }
}
