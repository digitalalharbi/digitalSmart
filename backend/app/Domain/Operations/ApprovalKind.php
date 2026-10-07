<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Access\Permission;

/**
 * What somebody is asking permission to do.
 *
 * The important thing this enum carries is `isEnactedByThisProduct()`, and today every case answers **false**.
 * The provider write adapters are a later phase, so approving a pause here does not pause anything: a person
 * goes and does it in the platform's own interface afterwards. Saying so is the whole point. An approval
 * screen that looked like it pressed the button would have somebody telling a client their campaign was
 * stopped while it kept spending — the single most expensive thing this module could get wrong.
 *
 * So an approved request moves to **awaiting a hand**, and only a person saying "I have done it" records that
 * it happened. The queue then publishes how much approved work is still waiting to be carried out, which is
 * the figure an agency never has.
 *
 * `decidedWith()` names a permission **in addition** to `approvals.decide`, where one already governs the
 * thing being changed. Changing what a client spends is signed off by whoever signs off the figure in the
 * first place; a second permission would let an approvals administrator quietly become a budget approver.
 */
enum ApprovalKind: string
{
    case CampaignLaunch = 'campaign_launch';

    case CampaignPause = 'campaign_pause';

    /** A budget, a bid or a spend limit on something already running. */
    case SpendChange = 'spend_change';

    /** Who sees the campaign. Changes where the money goes without changing how much it is. */
    case TargetingChange = 'targeting_change';

    /** Pushing an approved asset live. The asset's own sign-off is a separate record — see docs/CREATIVE.md. */
    case CreativePublish = 'creative_publish';

    /** A conversion definition, a container change — measurement rather than money. */
    case TrackingChange = 'tracking_change';

    public function label(): string
    {
        return match ($this) {
            self::CampaignLaunch => 'Launch a campaign',
            self::CampaignPause => 'Pause a campaign',
            self::SpendChange => 'Change spend',
            self::TargetingChange => 'Change targeting',
            self::CreativePublish => 'Publish creative',
            self::TrackingChange => 'Change tracking',
        };
    }

    /**
     * Whether this change moves a client's money.
     *
     * Targeting is included. It does not change how much is spent, but it changes who it is spent on, which
     * is the same conversation with the client and the same thing they will ask about afterwards.
     */
    public function touchesLiveSpend(): bool
    {
        return match ($this) {
            self::CampaignLaunch, self::CampaignPause, self::SpendChange, self::TargetingChange => true,
            self::CreativePublish, self::TrackingChange => false,
        };
    }

    /**
     * Whether approving this makes the change happen.
     *
     * False for every case, today, and the method exists so that stays visible rather than becoming an
     * assumption. The write adapters are Phase 24; until one exists for a kind, approval authorises a person
     * to go and make the change and the record says it is awaiting that hand.
     */
    public function isEnactedByThisProduct(): bool
    {
        return false;
    }

    /**
     * Why not, in a sentence an interface can show.
     *
     * Null once a kind can be enacted here. Named per kind rather than written once, because the missing piece
     * differs and "not implemented" tells a reader nothing they can act on.
     */
    public function enactmentBlockedBecause(): ?string
    {
        if ($this->isEnactedByThisProduct()) {
            return null;
        }

        return match ($this) {
            self::CampaignLaunch, self::CampaignPause => 'This product does not start or stop campaigns on the '
                .'platforms. Once this is approved, somebody makes the change in the platform and marks it done '
                .'here.',
            self::SpendChange => 'This product does not change live budgets or bids. Once this is approved, '
                .'somebody makes the change in the platform and marks it done here.',
            self::TargetingChange => 'This product does not change targeting on the platforms. Once this is '
                .'approved, somebody makes the change in the platform and marks it done here.',
            self::CreativePublish => 'This product does not upload creative to the platforms. Once this is '
                .'approved, somebody publishes it and marks it done here.',
            self::TrackingChange => 'This product does not write to analytics properties or tag containers. '
                .'Once this is approved, somebody makes the change and marks it done here.',
        };
    }

    /**
     * A permission required of the decider on top of `approvals.decide`, or null.
     *
     * See the note at the top of this enum: the person who signs off what a client spends is the person who
     * signs off changing it.
     */
    public function decidedWith(): ?Permission
    {
        return match ($this) {
            self::SpendChange, self::CampaignLaunch => Permission::BudgetsApprove,
            self::CreativePublish => Permission::CreativeApprove,
            self::TrackingChange => Permission::TrackingManage,
            /*
             * Pausing and retargeting need no second permission. Stopping spend is the safe direction, and
             * requiring a budget approver to stop a runaway campaign is how a runaway campaign keeps running
             * over a weekend.
             */
            self::CampaignPause, self::TargetingChange => null,
        };
    }

    /** What this kind is usually about, so a form can offer the right subject. Null where it varies. */
    public function usualSubject(): ?TaskSubjectType
    {
        return match ($this) {
            self::CampaignLaunch, self::CampaignPause, self::TargetingChange => TaskSubjectType::Campaign,
            self::SpendChange => TaskSubjectType::Budget,
            self::CreativePublish => TaskSubjectType::CreativeRequest,
            self::TrackingChange => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
