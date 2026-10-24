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
    case Activating = 'activating';
    case Active = 'active';
    case Failed = 'failed';
    case BlockedExternal = 'blocked_external';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
