<?php

declare(strict_types=1);

namespace App\Domain\Saas;

/**
 * What a workspace has bought or been given — the one catalogue, so no feature is a string literal scattered through the code.
 *
 * A feature is **not a permission**. Permission answers "is this person allowed?"; a feature answers "does this workspace have
 * it?". An owner holding every permission is still refused a feature the workspace's plan does not include.
 *
 * Some features are in every plan (`isEverywhere()`): the core of the product, never gated, so a plan change cannot take a
 * workspace's clients or tasks away. The rest are gated on the server by route middleware.
 */
enum Feature: string
{
    case Overview = 'overview';
    case Clients = 'clients';
    case Projects = 'projects';
    case CampaignsRead = 'campaigns_read';
    case CampaignLaunch = 'campaign_launch';
    case ProviderWrites = 'provider_writes';
    case Analytics = 'analytics';
    case AdvancedAnalytics = 'advanced_analytics';
    case Tracking = 'tracking';
    case TrackingForms = 'tracking_forms';
    case TrackingFunnels = 'tracking_funnels';
    case Reports = 'reports';
    case ClientPortal = 'client_portal';
    case CreativeIntelligence = 'creative_intelligence';
    case CreativeOperations = 'creative_operations';
    case Tasks = 'tasks';
    case Approvals = 'approvals';
    case Notifications = 'notifications';
    case Automation = 'automation';
    case Agents = 'agents';
    case Ai = 'ai';
    case Presentations = 'presentations';
    case Commerce = 'commerce';
    case Crm = 'crm';
    case Attribution = 'attribution';
    case WhiteLabel = 'white_label';
    case ApiAccess = 'api_access';

    /** In every plan and never gated: taking one away would take a workspace's own records away from it. */
    public function isEverywhere(): bool
    {
        return in_array($this, [
            self::Overview, self::Clients, self::Projects, self::CampaignsRead, self::Analytics, self::Tracking,
            self::Reports, self::Tasks, self::Approvals, self::Notifications,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
