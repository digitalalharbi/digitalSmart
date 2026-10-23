<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Dispatch;

enum DispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    /** Deliberately not sent; the reason is recorded. */
    case Skipped = 'skipped';
    /** A request went out and no answer came back. Never retried automatically: a second attempt can duplicate a conversion. */
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
