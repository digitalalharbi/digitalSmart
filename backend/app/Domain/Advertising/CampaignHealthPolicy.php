<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

use DateTimeImmutable;

/**
 * When a campaign needs somebody.
 *
 * Written as a policy object rather than as SQL for two reasons. It is the part of the Campaign Control
 * Center carrying a judgement, and a judgement belongs where it can be read, argued with and tested
 * exhaustively — not spread across a `CASE` expression that only runs against a database. And the same
 * rules have to produce the same verdict wherever they are asked, so there is exactly one of them.
 *
 * Every threshold here is a choice, and each one is stated with its reason. They are deliberately
 * conservative: this list is read by somebody with a hundred campaigns and twenty minutes, and a rule
 * that fires too readily turns the whole surface into noise they stop opening.
 */
final readonly class CampaignHealthPolicy
{
    /**
     * Below this, a window cannot carry a trend.
     *
     * Seven days rather than three: advertising has a weekly rhythm, and a Tuesday-to-Thursday sample
     * compared against a Saturday-to-Monday one produces a change that is a calendar artefact.
     */
    public const int MINIMUM_REPORTED_DAYS = 7;

    /** A live campaign whose figures are older than this is not reporting, whatever the numbers say. */
    public const int STALE_AFTER_HOURS = 48;

    /** Cost per result worse than this much, proportionally, is worth somebody's morning. */
    public const float COST_RISE_SERIOUS = 0.50;

    public const float COST_RISE_WATCH = 0.20;

    /** Delivery falling by this much is usually a budget, an audience or a rejection. */
    public const float DELIVERY_DROP_WATCH = 0.40;

    public function assess(CampaignVitals $vitals, DateTimeImmutable $now): CampaignVerdict
    {
        /*
         * What the platform says, first.
         *
         * A rejected campaign is the clearest finding there is and no amount of performance arithmetic
         * improves on it. A paused one is not a problem at all, and putting it in the attention queue is
         * how the queue stops being read. Both outrank every check below, which all assume a campaign
         * that is trying to run.
         */
        $fromStatus = $this->fromStatus($vitals->status);

        if ($fromStatus instanceof CampaignVerdict) {
            return $fromStatus;
        }

        /*
         * Staleness outranks performance, because performance computed from figures that stopped arriving
         * is a statement about the past presented as a statement about now. This is the one check that
         * can call a campaign critical while every number on the row looks fine.
         */
        if ($this->hasStoppedReporting($vitals, $now)) {
            return new CampaignVerdict(
                CampaignHealth::Critical,
                'Running, but no figures have arrived for two days. The numbers below are out of date.',
                'CAMPAIGN_STALE',
            );
        }

        if ($vitals->reportedDays < self::MINIMUM_REPORTED_DAYS) {
            return new CampaignVerdict(
                CampaignHealth::InsufficientData,
                sprintf(
                    'Only %d %s of figures so far. Too early to read a trend.',
                    $vitals->reportedDays,
                    $vitals->reportedDays === 1 ? 'day' : 'days',
                ),
                'CAMPAIGN_TOO_EARLY',
                ['days' => $vitals->reportedDays],
            );
        }

        /*
         * Spending with nothing to show. The strongest performance signal there is, and the only one that
         * does not need a comparison window — but it is stated only where the objective expects a counted
         * result, because a reach campaign has no results to be missing.
         */
        if ($this->spendsWithoutResults($vitals)) {
            return new CampaignVerdict(
                CampaignHealth::Critical,
                'Spending, and nothing has been recorded against it in this period.',
                'CAMPAIGN_SPEND_NO_RESULTS',
            );
        }

        $costChange = $this->costChange($vitals);

        if ($costChange !== null && $costChange >= self::COST_RISE_SERIOUS) {
            return new CampaignVerdict(
                CampaignHealth::NeedsAttention,
                sprintf('Each result is costing %d%% more than last period.', (int) round($costChange * 100)),
                'CAMPAIGN_COST_RISE',
                ['pct' => (int) round($costChange * 100)],
            );
        }

        if ($costChange !== null && $costChange >= self::COST_RISE_WATCH) {
            return new CampaignVerdict(
                CampaignHealth::Watch,
                sprintf('Each result is costing %d%% more than last period.', (int) round($costChange * 100)),
                'CAMPAIGN_COST_RISE',
                ['pct' => (int) round($costChange * 100)],
            );
        }

        $deliveryDrop = $this->deliveryDrop($vitals);

        if ($deliveryDrop !== null && $deliveryDrop >= self::DELIVERY_DROP_WATCH) {
            return new CampaignVerdict(
                CampaignHealth::Watch,
                sprintf('Spending %d%% less than last period.', (int) round($deliveryDrop * 100)),
                'CAMPAIGN_DELIVERY_DROP',
                ['pct' => (int) round($deliveryDrop * 100)],
            );
        }

        return new CampaignVerdict(CampaignHealth::Healthy, 'Delivering in line with last period.', 'CAMPAIGN_HEALTHY');
    }

    /**
     * The verdict the platform's own state forces, or null to carry on.
     *
     * Deliberately not a copy of `EntityStatus::needsAttention()`: that answers "is this unusual", and
     * this answers "what does somebody do about it", which are different questions with different
     * answers for `in_review`.
     */
    private function fromStatus(EntityStatus $status): ?CampaignVerdict
    {
        return match ($status) {
            EntityStatus::Active => null,
            EntityStatus::Rejected => new CampaignVerdict(
                CampaignHealth::Critical,
                'The platform rejected this campaign. It is not running.',
                'CAMPAIGN_REJECTED',
            ),
            EntityStatus::NotDelivering => new CampaignVerdict(
                CampaignHealth::Critical,
                'Active, and the platform is not delivering it.',
                'CAMPAIGN_NOT_DELIVERING',
            ),
            /* Normal, and brief. Worth seeing on a list; not worth waking anybody. */
            EntityStatus::InReview => new CampaignVerdict(
                CampaignHealth::Watch,
                'Waiting for the platform to review it.',
                'CAMPAIGN_IN_REVIEW',
            ),
            /*
             * The platform has not told us. Not healthy — nothing has been established — and not a
             * problem to act on either, which is exactly what "not enough data" means.
             */
            EntityStatus::Unknown => new CampaignVerdict(
                CampaignHealth::InsufficientData,
                'The platform has not reported this campaign’s state.',
                'CAMPAIGN_STATE_UNKNOWN',
            ),
            EntityStatus::Paused,
            EntityStatus::Completed,
            EntityStatus::Archived,
            EntityStatus::Removed => new CampaignVerdict(
                CampaignHealth::Inactive,
                sprintf('%s. Nothing to act on.', $status->label()),
                'CAMPAIGN_INACTIVE',
                ['status' => $status->value],
            ),
        };
    }

    private function hasStoppedReporting(CampaignVitals $vitals, DateTimeImmutable $now): bool
    {
        if ($vitals->lastSyncedAt === null) {
            /*
             * Never synced is only alarming once the campaign has had a chance to report. A campaign
             * created an hour ago has no figures and nothing is wrong, which the reported-days check
             * below states more accurately than this one could.
             */
            return false;
        }

        return $vitals->lastSyncedAt->modify(sprintf('+%d hours', self::STALE_AFTER_HOURS)) < $now;
    }

    private function spendsWithoutResults(CampaignVitals $vitals): bool
    {
        if (! $vitals->objective->hasCostPerResult()) {
            return false;
        }

        /* Null results means nobody reported, which is a measurement gap and not a performance verdict. */
        return ($vitals->spendMinor ?? 0) > 0 && $vitals->results !== null && $vitals->results <= 0.0;
    }

    /** Proportional rise in cost per result, or null when the comparison cannot be made. */
    private function costChange(CampaignVitals $vitals): ?float
    {
        $now = $vitals->costPerResult();
        $before = $vitals->previousCostPerResult();

        if ($now === null || $before === null || $before <= 0.0) {
            return null;
        }

        return ($now - $before) / $before;
    }

    private function deliveryDrop(CampaignVitals $vitals): ?float
    {
        $now = $vitals->spendMinor;
        $before = $vitals->previousSpendMinor;

        if ($now === null || $before === null || $before <= 0) {
            return null;
        }

        return $now >= $before ? null : ($before - $now) / $before;
    }
}
