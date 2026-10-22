<?php

declare(strict_types=1);

use App\Domain\Attention\AttentionSeverity;
use App\Domain\Notifications\AlertRule;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;

it('maps each health, budget and tracking code to one alert, and an unknown code to none', function (): void {
    expect(AlertRule::forReason('COST_RISE'))->toBe(NotificationKind::CostPerResultRise)
        ->and(AlertRule::forReason('SPEND_NO_RESULTS'))->toBe(NotificationKind::NoResults)
        ->and(AlertRule::forReason('DELIVERY_DROP'))->toBe(NotificationKind::CampaignUnderDelivering)
        ->and(AlertRule::forReason('NOT_DELIVERING'))->toBe(NotificationKind::CampaignUnderDelivering)
        ->and(AlertRule::forReason('REJECTED'))->toBe(NotificationKind::CampaignRejected)
        ->and(AlertRule::forReason('BUDGET_NEAR_LIMIT'))->toBe(NotificationKind::BudgetNearLimit)
        ->and(AlertRule::forReason('TRACKING_SOURCE_SILENT'))->toBe(NotificationKind::TrackingFailure)
        ->and(AlertRule::forReason('CAMPAIGN_STALE'))->toBeNull()
        ->and(AlertRule::forReason(null))->toBeNull();
});

it('keeps the queue\'s own severity', function (): void {
    expect(AlertRule::severityOf(AttentionSeverity::Critical))->toBe(NotificationSeverity::Critical)
        ->and(AlertRule::severityOf(AttentionSeverity::NeedsAttention))->toBe(NotificationSeverity::Attention)
        ->and(AlertRule::severityOf(AttentionSeverity::Watch))->toBe(NotificationSeverity::Info);
});

it('calls a ROAS drop at a fifth, critical at half, and nothing from no base', function (): void {
    expect(AlertRule::roasDrop(4.0, 3.3))->toBeNull()
        ->and(AlertRule::roasDrop(4.0, 3.1))->toBe(NotificationSeverity::Attention)
        ->and(AlertRule::roasDrop(4.0, 2.0))->toBe(NotificationSeverity::Critical)
        ->and(AlertRule::roasDrop(4.0, 3.5))->toBeNull()
        ->and(AlertRule::roasDrop(0.0, 2.0))->toBeNull()
        ->and(AlertRule::roasDrop(null, 2.0))->toBeNull();
});

it('calls a spend spike at half again, critical at double', function (): void {
    expect(AlertRule::spendSpike(100.0, 149.0))->toBeNull()
        ->and(AlertRule::spendSpike(100.0, 150.0))->toBe(NotificationSeverity::Attention)
        ->and(AlertRule::spendSpike(100.0, 200.0))->toBe(NotificationSeverity::Critical)
        ->and(AlertRule::spendSpike(0.0, 200.0))->toBeNull();
});
