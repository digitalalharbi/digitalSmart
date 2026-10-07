<?php

declare(strict_types=1);

namespace App\Domain\Creative;

use DateTimeImmutable;

/**
 * Where a recorded specification came from, and when somebody last looked.
 *
 * This object is the reason the spec layer can be trusted at all, and it exists because of a finding
 * worth stating plainly: **the ad platforms do not publish their creative specifications in any form a
 * program can read.** Meta's Marketing API reference points at a marketing page rendered by JavaScript;
 * TikTok's and Snapchat's numbers live in help centres; the third-party guides that aggregate them
 * disagree with each other and go stale without saying so.
 *
 * So this product does not pretend to know. A specification is **data a person recorded, with the page
 * they read it on and the date they read it**, and every verdict computed against one carries that date.
 * A number with no source is a number nobody can defend when a client asks why their video was rejected.
 */
final readonly class SpecProvenance
{
    /**
     * How long a recorded specification is treated as current.
     *
     * Six months. Long enough not to nag, short enough that a platform's quiet change gets caught within
     * a reporting cycle rather than after a campaign has run on the wrong sizes.
     */
    public const int FRESH_DAYS = 182;

    public function __construct(
        /** The page it was read on. Required: a specification with no source is folklore. */
        public string $sourceUrl,
        /** When somebody last read that page — not when the row was written. */
        public DateTimeImmutable $documentedAt,
        /** Who read it, so a disagreement has somebody to ask. */
        public ?string $recordedBy = null,
        /** Anything the page said that the structured fields cannot hold. */
        public ?string $note = null,
    ) {}

    public function isStale(DateTimeImmutable $now): bool
    {
        return $this->documentedAt < $now->modify('-'.self::FRESH_DAYS.' days');
    }

    public function ageInDays(DateTimeImmutable $now): int
    {
        return (int) $this->documentedAt->diff($now)->days;
    }

    /**
     * The verdict this provenance allows, whatever the measurement said.
     *
     * A stale specification downgrades a pass to `Stale` and leaves a failure alone: an asset outside a
     * requirement recorded a year ago is still worth looking at, while an asset inside one is not
     * evidence that today's platform agrees.
     */
    public function temper(SpecVerdict $measured, DateTimeImmutable $now): SpecVerdict
    {
        if (! $this->isStale($now)) {
            return $measured;
        }

        return $measured === SpecVerdict::Pass ? SpecVerdict::Stale : $measured;
    }
}
