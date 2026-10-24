<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/**
 * One ad account's part of a launch. **`created` is not `verified`**: a platform answering a create call proves nothing until the
 * object is read back and matches what was asked for.
 */
enum ExecutionStatus: string
{
    case Queued = 'queued';
    case Creating = 'creating';
    /** The platform answered with ids; not yet read back. */
    case Created = 'created';
    /** Read back from the platform and matching the plan, paused. */
    case Verified = 'verified';
    /** Read back, and something differs from the plan. Nothing is changed automatically. */
    case ReadbackMismatch = 'readback_mismatch';
    case Failed = 'failed';
    /** The request may or may not have created something. Never retried until reconciled. */
    case Unknown = 'unknown';
    /** Not attempted: the preflight stopped this account. */
    case Skipped = 'skipped';
    /** Not attempted: it needs access only the platform can grant. */
    case BlockedExternal = 'blocked_external';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Queued, self::Creating, self::Created], true);
    }

    public function createdSomething(): bool
    {
        return in_array($this, [self::Created, self::Verified, self::ReadbackMismatch], true);
    }

    /** Only a failure the platform did not act on, or a reconciled not-found, may be tried again. */
    public function mayRetry(): bool
    {
        return $this === self::Failed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
