<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/**
 * One account's switch from paused to delivering. **`active` only after the platform confirms it**, read back — a request to
 * activate that was accepted is `activating`, never active.
 */
enum ActivationStatus: string
{
    case Pending = 'pending';
    /** Approved and queued; no worker has taken it yet. */
    case Activating = 'activating';
    /** A worker took it and the request is on its way: the platform may be switching it on right now. */
    case Sending = 'sending';
    case Active = 'active';
    /** Proven not switched on, or refused: it may be approved again. */
    case Failed = 'failed';
    /** The request may or may not have switched it on. Never re-sent until the platform has been read. */
    case Unknown = 'unknown';
    case BlockedExternal = 'blocked_external';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
