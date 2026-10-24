<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Blueprint;

use App\Domain\Advertising\CampaignObjective;

/**
 * The eight intents a campaign can be planned for across platforms. Each platform's translator decides what it calls them, or
 * says it has nothing equivalent — no intent is forced onto an objective that means something else.
 */
enum LaunchObjective: string
{
    case Awareness = 'awareness';
    case Reach = 'reach';
    case Traffic = 'traffic';
    case Engagement = 'engagement';
    case Video = 'video';
    case Leads = 'leads';
    case Sales = 'sales';
    case AppInstall = 'app_install';

    /** The same intent in the vocabulary the rest of the product already reads campaigns in. */
    public function campaignObjective(): CampaignObjective
    {
        return match ($this) {
            self::Awareness => CampaignObjective::Awareness,
            self::Reach => CampaignObjective::Reach,
            self::Traffic => CampaignObjective::Traffic,
            self::Engagement => CampaignObjective::Engagement,
            self::Video => CampaignObjective::VideoViews,
            self::Leads => CampaignObjective::Leads,
            self::Sales => CampaignObjective::Sales,
            self::AppInstall => CampaignObjective::AppPromotion,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
