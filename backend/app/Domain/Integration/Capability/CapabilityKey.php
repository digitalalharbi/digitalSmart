<?php

declare(strict_types=1);

namespace App\Domain\Integration\Capability;

use App\Domain\Integration\WriteOperation;

/**
 * Everything the campaign launch path may ask of a platform, named once. A blueprint, a preflight and a launch read capability
 * through these keys and nothing else.
 */
enum CapabilityKey: string
{
    case CampaignCreate = 'campaign.create';
    case CampaignUpdate = 'campaign.update';
    case CampaignPause = 'campaign.pause';
    case CampaignResume = 'campaign.resume';
    case CampaignActivate = 'campaign.activate';
    case CampaignBudgetUpdate = 'campaign.budget.update';
    case AdGroupCreate = 'ad_group.create';
    case AdGroupUpdate = 'ad_group.update';
    case AdGroupPause = 'ad_group.pause';
    case AdGroupResume = 'ad_group.resume';
    case AdCreate = 'ad.create';
    case AdUpdate = 'ad.update';
    case AdPause = 'ad.pause';
    case AdResume = 'ad.resume';
    case CreativeUpload = 'creative.upload';
    case TargetingLocation = 'targeting.location';
    case TargetingDemographic = 'targeting.demographic';
    case TargetingInterest = 'targeting.interest';
    case TargetingCustomAudience = 'targeting.custom_audience';
    case TargetingLookalike = 'targeting.lookalike';
    case PlacementManual = 'placement.manual';
    case PlacementAutomatic = 'placement.automatic';
    case ConversionOptimize = 'conversion.optimize';
    case ValidationPreflight = 'validation.preflight';
    case IdempotencyNative = 'idempotency.native';
    case Readback = 'readback';

    /** The status and budget writes the product already carries out, whose evidence lives with them. */
    public function writeOperation(): ?WriteOperation
    {
        return match ($this) {
            self::CampaignPause => WriteOperation::PauseCampaign,
            self::CampaignResume => WriteOperation::ResumeCampaign,
            self::CampaignBudgetUpdate => WriteOperation::SetCampaignDailyBudget,
            self::AdGroupPause => WriteOperation::PauseAdGroup,
            self::AdGroupResume => WriteOperation::ResumeAdGroup,
            self::AdPause => WriteOperation::PauseAd,
            self::AdResume => WriteOperation::ResumeAd,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
