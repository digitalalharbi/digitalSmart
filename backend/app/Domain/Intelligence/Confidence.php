<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Advertising\MetricSource;

/**
 * How much weight a finding can carry, derived rather than asserted.
 *
 * Three inputs, each of which can ruin a finding on its own:
 *
 *  - **The source state.** A pattern in sandbox fixtures is a pattern in fixtures.
 *  - **The denominator.** A doubling of conversion rate on nine clicks is noise with a percentage sign on it, and
 *    it is the single most common way an analytics tool produces a confident lie.
 *  - **Whether both periods have data.** A change against a period with nothing in it is not a change.
 *
 * There is no fourth input and no model involved: a confidence a language model wrote would be a number with no
 * derivation, which is exactly what this class exists to avoid.
 */
enum Confidence: string
{
    /** Production data, a denominator worth trusting, both periods present. */
    case Firm = 'firm';

    /** One of the three is weak. Worth saying, not worth acting on alone. */
    case Tentative = 'tentative';

    /** Said only so a reader knows it was looked at. Never the basis of a recommendation. */
    case Indicative = 'indicative';

    /**
     * The smallest denominator a rate may be computed from before this product will state it.
     *
     * Thirty is not a statistical threshold and does not pretend to be one. It is the point below which a daily
     * figure for a campaign is a handful of events, and a product that quoted a rate from four of them would be
     * quoting the weather.
     */
    public const int DENOMINATOR_FLOOR = 30;

    public static function of(MetricSource $source, int $denominator, bool $bothPeriodsPresent): self
    {
        if (! $bothPeriodsPresent || $denominator < self::DENOMINATOR_FLOOR) {
            return self::Indicative;
        }

        /*
         * Only a current provider read is firm. The three in the middle are the ones that catch people out:
         * `Stale` is a real figure that has stopped moving, `Partial` understates its own totals by construction,
         * and `Manual` is a person's assertion rather than a provider's. Each is worth stating and none is worth
         * a recommendation on its own.
         *
         * `Sandbox` and `Estimated` fall through to indicative: a pattern in fixtures is a pattern in fixtures,
         * and a projection is not an observation.
         */
        return match ($source) {
            MetricSource::Live, MetricSource::Synced => self::Firm,
            MetricSource::Stale, MetricSource::Partial, MetricSource::Manual => self::Tentative,
            MetricSource::Sandbox, MetricSource::Estimated => self::Indicative,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Firm => 'Firm',
            self::Tentative => 'Tentative',
            self::Indicative => 'Indicative only',
        };
    }

    /**
     * Whether a recommendation may be built on this.
     *
     * Indicative findings never carry one. A recommendation is a sentence telling somebody to move a client's
     * money, and "we think, from twelve clicks" is not a reason to move it.
     */
    public function supportsRecommendation(): bool
    {
        return $this !== self::Indicative;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
