<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Attention\AttentionSeverity;

/**
 * Which alert a signal becomes, and how loud — the whole of the judgement, in one readable place.
 *
 * The signals are the product's own: a campaign's health code, a budget's risk, a tracking issue's code, a
 * creative's verdict, a figure against its earlier period. Nothing here looks at a number the rest of the product
 * has not already looked at; this only says which bell it rings. A code with no row below is not an alert.
 */
final class AlertRule
{
    /** A return on ad spend a fifth lower than the earlier period is worth saying. */
    public const float ROAS_DROP = 0.2;

    /** Spend half again the earlier period is a spike; double is a critical one. */
    public const float SPEND_SPIKE = 0.5;

    public const float SPEND_SPIKE_CRITICAL = 1.0;

    /** @var array<string, NotificationKind> */
    private const array BY_REASON = [
        'COST_RISE' => NotificationKind::CostPerResultRise,
        'SPEND_NO_RESULTS' => NotificationKind::NoResults,
        'DELIVERY_DROP' => NotificationKind::CampaignUnderDelivering,
        'NOT_DELIVERING' => NotificationKind::CampaignUnderDelivering,
        'REJECTED' => NotificationKind::CampaignRejected,
        'BUDGET_NEAR_LIMIT' => NotificationKind::BudgetNearLimit,
        'BUDGET_OVERSPEND' => NotificationKind::BudgetNearLimit,
        'BUDGET_PLATFORM_OVER' => NotificationKind::BudgetNearLimit,
        'BUDGET_CAMPAIGN_OVER' => NotificationKind::BudgetNearLimit,
    ];

    public static function forReason(?string $reasonCode): ?NotificationKind
    {
        if ($reasonCode === null) {
            return null;
        }

        if (str_starts_with($reasonCode, 'TRACKING_')) {
            return NotificationKind::TrackingFailure;
        }

        return self::BY_REASON[$reasonCode] ?? null;
    }

    public static function severityOf(AttentionSeverity $severity): NotificationSeverity
    {
        return match ($severity) {
            AttentionSeverity::Critical => NotificationSeverity::Critical,
            AttentionSeverity::NeedsAttention => NotificationSeverity::Attention,
            AttentionSeverity::Watch => NotificationSeverity::Info,
        };
    }

    /** The change in the bad direction, or null when there is no base to change from or no drop worth saying. */
    public static function roasDrop(?float $before, ?float $after): ?NotificationSeverity
    {
        if ($before === null || $after === null || $before <= 0.0) {
            return null;
        }

        $adverse = ($before - $after) / $before;

        return $adverse >= self::ROAS_DROP ? NotificationSeverity::forAdverseChange($adverse) : null;
    }

    public static function spendSpike(?float $before, ?float $after): ?NotificationSeverity
    {
        if ($before === null || $after === null || $before <= 0.0) {
            return null;
        }

        $rise = ($after - $before) / $before;

        if ($rise >= self::SPEND_SPIKE_CRITICAL) {
            return NotificationSeverity::Critical;
        }

        return $rise >= self::SPEND_SPIKE ? NotificationSeverity::Attention : null;
    }
}
