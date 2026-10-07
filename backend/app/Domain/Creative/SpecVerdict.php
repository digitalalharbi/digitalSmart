<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * What checking an asset against a placement's specification actually established.
 *
 * Five outcomes, and the four that are not `Pass` exist because "valid" is the easiest lie a creative
 * tool can tell. A file whose dimensions nobody read is not valid; a placement nobody has recorded a
 * specification for cannot be checked at all; and a specification somebody read eighteen months ago is
 * not evidence about today's platform.
 *
 * The overall verdict for an asset is the **weakest** of its checks, for the same reason a metric's
 * source is: one unchecked dimension in a set of twelve passes makes the set unchecked, and a reader
 * deciding whether to publish needs the floor rather than the average.
 */
enum SpecVerdict: string
{
    /** Measured, and within the recorded requirement. */
    case Pass = 'pass';

    /** Measured, and outside it. The only verdict that justifies blocking a publish. */
    case Fail = 'fail';

    /**
     * The requirement exists and this property could not be measured.
     *
     * A video whose duration the product could not read, an image it could not open. Distinct from a
     * failure: nothing is known, and reporting it as a pass is how an asset that breaks on upload gets
     * through a review.
     */
    case Unchecked = 'unchecked';

    /**
     * Nobody has recorded a specification for this placement.
     *
     * The honest default for most placements, and itself useful: it tells an agency which placements
     * they are publishing to blind. Platforms do not publish their creative specifications in a form a
     * program can read — see docs/CREATIVE.md — so this product records them with provenance rather
     * than pretending to know.
     */
    case NoSpec = 'no_spec';

    /**
     * A specification was found and is older than the product is willing to vouch for.
     *
     * Checked against anyway, with the verdict carrying this instead of a pass: the platforms change
     * their requirements without notice, and a confident green tick citing a page nobody has read since
     * last year is worse than an honest shrug.
     */
    case Stale = 'stale';

    /** Rank, weakest first, so a set of checks can be reduced to the floor of what it establishes. */
    public function rank(): int
    {
        return match ($this) {
            self::Fail => 0,
            self::NoSpec => 1,
            self::Unchecked => 2,
            self::Stale => 3,
            self::Pass => 4,
        };
    }

    /**
     * The weakest verdict in a set.
     *
     * An empty set is `NoSpec` rather than `Pass`: having checked nothing is not having found nothing
     * wrong, and the difference is the whole point of this enum.
     *
     * @param  iterable<self>  $verdicts
     */
    public static function weakest(iterable $verdicts): self
    {
        $weakest = null;

        foreach ($verdicts as $verdict) {
            if ($weakest === null || $verdict->rank() < $weakest->rank()) {
                $weakest = $verdict;
            }
        }

        return $weakest ?? self::NoSpec;
    }

    /**
     * Whether this verdict justifies stopping somebody from publishing.
     *
     * Only a measured failure does. Blocking on `Unchecked` or `NoSpec` would make the product's own
     * gaps into the user's problem, and they would learn to click past the warning — which is how a
     * real failure gets clicked past too.
     */
    public function blocksPublishing(): bool
    {
        return $this === self::Fail;
    }

    /** Whether the product actually established anything here. */
    public function isEvidence(): bool
    {
        return $this === self::Pass || $this === self::Fail;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Within spec',
            self::Fail => 'Outside spec',
            self::Unchecked => 'Not checked',
            self::NoSpec => 'No spec recorded',
            self::Stale => 'Spec may be out of date',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
