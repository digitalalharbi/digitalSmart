<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Whether a report recomputes when it is opened, or shows what was true when it was taken.
 *
 * The distinction this module cannot afford to blur. A client who was sent a figure in March and opens the same
 * link in April must either see March's figure or be told plainly that they are looking at today's — and a link
 * that quietly recomputed would make "the report said 1.2 million" an unanswerable argument, because the report no
 * longer says it.
 *
 * So a snapshot is **stored output**, not a stored query: the rendered sections, their figures, their source
 * states and the moment they were taken, written once and never recomputed. The cost is storage. The alternative
 * costs an agency its credibility exactly once.
 */
enum RenderMode: string
{
    /** Recomputed on every open, against the report's window rule. Always labelled with the moment it ran. */
    case Live = 'live';

    /** Frozen output, with the moment it was taken. Never recomputed. */
    case Snapshot = 'snapshot';

    public function isFrozen(): bool
    {
        return $this === self::Snapshot;
    }

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live — recalculated every time it is opened',
            self::Snapshot => 'A snapshot — the figures as they were when it was taken',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
