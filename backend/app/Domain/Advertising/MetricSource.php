<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * Where a number came from, and how much it can be trusted.
 *
 * Every figure the platform displays carries one of these. The rule this
 * enforces is absolute: a synthetic number is never presented as a live one.
 * A chart with no source state is a chart that can lie.
 */
enum MetricSource: string
{
    /** Read from the provider within the freshness window. */
    case Live = 'live';
    /** Synced from the provider, but the window has moved on. */
    case Synced = 'synced';
    /** Older than the freshness threshold; shown, but flagged. */
    case Stale = 'stale';
    /** The sync covered only part of the range, so totals understate reality. */
    case Partial = 'partial';
    /** From sandbox fixtures. Never, under any circumstances, presented as real. */
    case Sandbox = 'sandbox';
    /** Entered by a person — an offline conversion, a manual spend adjustment. */
    case Manual = 'manual';
    /** A projection. Always labelled, never totalled with actuals. */
    case Estimated = 'estimated';

    /** Whether this number reflects money that actually moved. */
    public function isActual(): bool
    {
        return in_array($this, [self::Live, self::Synced, self::Stale, self::Partial, self::Manual], true);
    }

    /** Whether the interface must visibly mark it. */
    public function requiresLabel(): bool
    {
        return $this !== self::Live;
    }

    /**
     * Whether two sources may be added together.
     *
     * Actuals combine freely: a stale day and a live day are both money that
     * moved. Sandbox and estimated figures combine only with their own kind,
     * because a total that mixes a projection with a real spend is a number
     * that means nothing and reads like a fact.
     */
    public function mixesWith(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $this->isActual() && $other->isActual();
    }

    /**
     * The source an aggregate of these must carry.
     *
     * A total is only as trustworthy as its weakest input, so one stale day
     * makes the month stale and one sandbox row makes the figure sandbox. The
     * label travels with the number instead of being decided at the edge.
     *
     * @param  iterable<self>  $sources
     */
    public static function weakest(iterable $sources): self
    {
        $weakest = null;

        foreach ($sources as $source) {
            if ($weakest === null || $source->tier() > $weakest->tier()) {
                $weakest = $source;
            }
        }

        return $weakest ?? self::Live;
    }

    /**
     * How loudly this must be labelled. Higher wins when sources are combined.
     *
     * Estimated outranks sandbox: a projection shown as a measurement is the
     * more expensive mistake, because it survives the move to live credentials.
     */
    public function tier(): int
    {
        return match ($this) {
            self::Live => 0,
            self::Synced => 1,
            self::Manual => 2,
            self::Stale => 3,
            self::Partial => 4,
            self::Sandbox => 5,
            self::Estimated => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live',
            self::Synced => 'Synced',
            self::Stale => 'Stale',
            self::Partial => 'Partial',
            self::Sandbox => 'Sandbox',
            self::Manual => 'Manual',
            self::Estimated => 'Estimated',
        };
    }
}
