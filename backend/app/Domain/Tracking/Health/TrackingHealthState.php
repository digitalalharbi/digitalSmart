<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Health;

/**
 * How trustworthy one piece of tracking is right now. A fixed vocabulary shared with every figure in the product.
 *
 * Nothing here is green by default: `LIVE` and `SYNCED` are earned by evidence this product holds, and the states that mean
 * "we cannot say" (`NOT_CONFIGURED`, `AWAITING_CREDENTIALS`, `UNSUPPORTED`) are as visible as the good ones.
 */
enum TrackingHealthState: string
{
    case Live = 'LIVE';
    case Synced = 'SYNCED';
    case Stale = 'STALE';
    case Partial = 'PARTIAL';
    case Sandbox = 'SANDBOX';
    case Manual = 'MANUAL';
    case Estimated = 'ESTIMATED';
    case Attributed = 'ATTRIBUTED';
    case NotConfigured = 'NOT_CONFIGURED';
    case AwaitingCredentials = 'AWAITING_CREDENTIALS';
    case Unsupported = 'UNSUPPORTED';

    /** Whether somebody should look at it. A missing credential is a to-do; a stale feed is a fault. */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Stale, self::Partial, self::NotConfigured], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
