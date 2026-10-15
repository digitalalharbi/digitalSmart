<?php

declare(strict_types=1);

namespace App\Domain\Integration\Sync;

/**
 * Why an account is being synced now, which decides where its job waits.
 *
 * Four queues in strict priority order. A worker started with `--queue=sync-recovery,sync,sync-low,
 * sync-backfill` drains them in that order, so current monitoring is never behind historical import:
 * a hundred new accounts being backfilled cannot delay the morning's view of the campaigns spending today.
 */
enum SyncClass: string
{
    /** Active, and its figures have stopped arriving. The one a person is most likely to be waiting on. */
    case Recovery = 'recovery';
    /** Active campaigns; kept current. */
    case Live = 'live';
    /** Nothing running. Worth keeping, not worth the rate limit hourly. */
    case Dormant = 'dormant';
    /** Every bound project is finished. History is kept; nothing recurring is spent on it. */
    case Archived = 'archived';
    /** Somebody asked for a window. Lowest priority of everything that runs. */
    case Backfill = 'backfill';

    public function queue(): string
    {
        return match ($this) {
            self::Recovery => 'sync-recovery',
            self::Live => 'sync',
            self::Dormant => 'sync-low',
            self::Backfill => 'sync-backfill',
            self::Archived => 'sync-low',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::Recovery => 0,
            self::Live => 1,
            self::Dormant => 2,
            self::Backfill => 3,
            self::Archived => 4,
        };
    }

    /** Minutes between recurring syncs; null where nothing recurs. */
    public function cadenceMinutes(): ?int
    {
        return match ($this) {
            self::Recovery, self::Live => 60,
            self::Dormant => 360,
            self::Archived, self::Backfill => null,
        };
    }
}
