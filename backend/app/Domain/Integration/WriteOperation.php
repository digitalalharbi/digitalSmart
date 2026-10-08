<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Operations\ApprovalKind;

/**
 * Something this product can ask a platform to change.
 *
 * Three operations, and the list is short on purpose. Each one had to be read in the provider's own current
 * documentation before it could appear here — see {@see Provider::writeReference()} — and a short list of
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

    public function label(): string
    {
        return match ($this) {
            self::PauseCampaign => 'Pause a campaign',
            self::ResumeCampaign => 'Resume a campaign',
            self::SetCampaignDailyBudget => 'Change a campaign\'s daily budget',
        };
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
            self::PauseCampaign => ApprovalKind::CampaignPause,
            self::ResumeCampaign => ApprovalKind::CampaignLaunch,
            self::SetCampaignDailyBudget => ApprovalKind::SpendChange,
        };
    }

    /** Whether carrying this out changes what a client spends. True for all three; stated rather than assumed. */
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
            self::PauseCampaign, self::ResumeCampaign => ProviderCapability::CampaignStatusMutation,
            self::SetCampaignDailyBudget => ProviderCapability::BudgetMutation,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
