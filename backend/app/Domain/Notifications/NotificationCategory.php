<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Which tab of the notification centre a kind belongs under.
 *
 * Decided per kind, in the enum, so a tab is a filter on a stored column rather than a list of kinds the
 * interface keeps by hand — the list that drifts the first time a kind is added.
 */
enum NotificationCategory: string
{
    case Performance = 'performance';
    case Budget = 'budget';
    case Creative = 'creative';
    case Tracking = 'tracking';
    case Approvals = 'approvals';
    case Reports = 'reports';
    /** Tasks, leads and client requests: things between people rather than figures. */
    case Work = 'work';

    public function label(): string
    {
        return match ($this) {
            self::Performance => 'Performance',
            self::Budget => 'Budget',
            self::Creative => 'Creative',
            self::Tracking => 'Tracking',
            self::Approvals => 'Approvals',
            self::Reports => 'Reports',
            self::Work => 'Work',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
