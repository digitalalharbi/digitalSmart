<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Advertising\AdEntityType;
use App\Domain\Operations\ApprovalKind;

/**
 * Something this product can ask a platform to change.
 *
 * Three changes — pause, resume, daily budget — at three levels, and the list is short on purpose. Each one, at
 * each level, had to be read in the provider's own current documentation before it could appear here — see {@see Provider::writeReference()} — and a short list of
 * verified writes is worth more than a long list of claims. "Do not claim provider capability merely because the
 * provider's interface offers it" is the rule this enum exists to keep.
 *
 * Every operation maps to the {@see ApprovalKind} that authorises it. Nothing here can be executed without an
 * approved request of that kind, which is what makes this a second deliberate act rather than a side effect of
 * approving something.
 */
enum WriteOperation: string
{
    case PauseCampaign = 'pause_campaign';

    case ResumeCampaign = 'resume_campaign';

    /** The daily budget on a campaign already running. In the account's own currency, in minor units. */
    case SetCampaignDailyBudget = 'set_campaign_daily_budget';

    /* One level down: an ad group (a Meta ad set, a Snapchat ad squad, an X line item, a LinkedIn campaign). */
    case PauseAdGroup = 'pause_ad_group';

    case ResumeAdGroup = 'resume_ad_group';

    /** Only where the platform keeps a daily budget on the ad group itself — Google Ads, for one, does not. */
    case SetAdGroupDailyBudget = 'set_ad_group_daily_budget';

    /* And the ad. No budget at this level on any platform this product reads: an ad spends its parent's money. */
    case PauseAd = 'pause_ad';

    case ResumeAd = 'resume_ad';

    public function label(): string
    {
        return match ($this) {
            self::PauseCampaign => 'Pause a campaign',
            self::ResumeCampaign => 'Resume a campaign',
            self::SetCampaignDailyBudget => 'Change a campaign\'s daily budget',
            self::PauseAdGroup => 'Pause an ad group',
            self::ResumeAdGroup => 'Resume an ad group',
            self::SetAdGroupDailyBudget => 'Change an ad group\'s daily budget',
            self::PauseAd => 'Pause an ad',
            self::ResumeAd => 'Resume an ad',
        };
    }

    /** The entity this changes. Support at one level never implies support at another. */
    public function level(): AdEntityType
    {
        return match ($this) {
            self::PauseCampaign, self::ResumeCampaign, self::SetCampaignDailyBudget => AdEntityType::Campaign,
            self::PauseAdGroup, self::ResumeAdGroup, self::SetAdGroupDailyBudget => AdEntityType::AdGroup,
            self::PauseAd, self::ResumeAd => AdEntityType::Ad,
        };
    }

    public function isBudget(): bool
    {
        return in_array($this, [self::SetCampaignDailyBudget, self::SetAdGroupDailyBudget], true);
    }

    public function isPause(): bool
    {
        return in_array($this, [self::PauseCampaign, self::PauseAdGroup, self::PauseAd], true);
    }

    /**
     * The operation at a level for a kind of change — null where that level has no such change (an ad has no budget).
     *
     * @param  'pause'|'resume'|'budget'  $change
     */
    public static function at(AdEntityType $level, string $change): ?self
    {
        foreach (self::cases() as $operation) {
            if ($operation->level() === $level && $operation->change() === $change) {
                return $operation;
            }
        }

        return null;
    }

    /** @return 'pause'|'resume'|'budget' */
    public function change(): string
    {
        return $this->isBudget() ? 'budget' : ($this->isPause() ? 'pause' : 'resume');
    }

    /**
     * The operation an approved request authorises: its kind says which change, the proposal's `level` says which
     * entity — absent means a campaign, which is what every proposal before ad-group rules named.
     *
     * @param  array<string, mixed>  $proposal
     */
    public static function forApproval(ApprovalKind $kind, array $proposal): ?self
    {
        $level = AdEntityType::tryFrom(is_string($proposal['level'] ?? null) ? $proposal['level'] : 'campaign');

        if ($level === null || $level === AdEntityType::Account) {
            return null;
        }

        foreach (self::cases() as $operation) {
            if ($operation->authorisedBy() === $kind && $operation->level() === $level) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * The approval that authorises this.
     *
     * Resuming a campaign is a launch rather than a pause: it starts money moving again, and the person who
     * signs off starting spend is not always the person who signs off stopping it. Pausing deliberately needs no
     * budget approver — requiring one to stop a runaway campaign is how a runaway campaign keeps running over a
     * weekend, which is the argument `ApprovalKind::decidedWith()` already makes.
     */
    public function authorisedBy(): ApprovalKind
    {
        return match ($this) {
            self::PauseCampaign, self::PauseAdGroup, self::PauseAd => ApprovalKind::CampaignPause,
            self::ResumeCampaign, self::ResumeAdGroup, self::ResumeAd => ApprovalKind::CampaignLaunch,
            self::SetCampaignDailyBudget, self::SetAdGroupDailyBudget => ApprovalKind::SpendChange,
        };
    }

    /** Whether carrying this out changes what a client spends. True for all of them; stated rather than assumed. */
    public function touchesLiveSpend(): bool
    {
        return true;
    }

    /**
     * The capability a provider connection must hold.
     *
     * Two of these are a status change and one is a budget change, which is the distinction the capability model
     * already draws — so a workspace that has the first and not the second cannot have the second applied to it
     * by this operation being added later.
     */
    public function requires(): ProviderCapability
    {
        return match ($this) {
            self::PauseCampaign, self::ResumeCampaign, self::PauseAdGroup, self::ResumeAdGroup, self::PauseAd,
            self::ResumeAd => ProviderCapability::CampaignStatusMutation,
            self::SetCampaignDailyBudget, self::SetAdGroupDailyBudget => ProviderCapability::BudgetMutation,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
