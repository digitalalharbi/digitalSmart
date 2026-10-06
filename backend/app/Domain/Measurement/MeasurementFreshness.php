<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use DateTimeImmutable;

/**
 * How current a measurement source's data is.
 *
 * The states are the same vocabulary the rest of the product uses for a figure's source,
 * and they are decided here from two facts: when the source last *synced* and when it last
 * reported an *event*. Those are different questions and both matter — a GA4 property that
 * synced an hour ago but has seen no event for three days is working perfectly and
 * measuring nothing, which is the exact failure a tracking centre exists to catch.
 */
enum MeasurementFreshness: string
{
    /** Synced recently, and events are arriving. */
    case Live = 'live';

    /** Synced, but nothing has been read since the last window. */
    case Synced = 'synced';

    /** The last sync is old enough that the figures should not be trusted as current. */
    case Stale = 'stale';

    /** Reachable, but reporting nothing. Working and measuring nothing. */
    case Silent = 'silent';

    /** Never connected, or never successfully read. */
    case NeverSynced = 'never_synced';

    /** Hours after which a sync is stale. A day, because most providers report daily. */
    public const int STALE_AFTER_HOURS = 36;

    /** Hours of no events before a source is called silent rather than quiet. */
    public const int SILENT_AFTER_HOURS = 48;

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live',
            self::Synced => 'Synced',
            self::Stale => 'Stale',
            self::Silent => 'No recent events',
            self::NeverSynced => 'Never synced',
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::Stale || $this === self::Silent || $this === self::NeverSynced;
    }

    /**
     * Work out the state from the two timestamps and now.
     *
     * Order matters, and it is the order somebody would ask the questions in: has it ever
     * worked, is it still working, and is it still seeing anything?
     */
    public static function of(
        ?DateTimeImmutable $lastSyncedAt,
        ?DateTimeImmutable $lastEventAt,
        DateTimeImmutable $now,
    ): self {
        if ($lastSyncedAt === null) {
            return self::NeverSynced;
        }

        if (self::hoursBetween($lastSyncedAt, $now) > self::STALE_AFTER_HOURS) {
            return self::Stale;
        }

        /*
         * Null here does not mean "no events ever" for every kind of source — a tag manager
         * reports no events at all, it reports configuration. Those sources are reported as
         * synced rather than silent, which is why the caller passes null for them.
         */
        if ($lastEventAt === null) {
            return self::Synced;
        }

        return self::hoursBetween($lastEventAt, $now) > self::SILENT_AFTER_HOURS
            ? self::Silent
            : self::Live;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    private static function hoursBetween(DateTimeImmutable $then, DateTimeImmutable $now): float
    {
        return ($now->getTimestamp() - $then->getTimestamp()) / 3600;
    }
}
