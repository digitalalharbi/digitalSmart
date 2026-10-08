<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

/**
 * What a slide is made of.
 *
 * Six shapes, and the list is closed for the same reason the reporting module's is: a deck whose slides could be
 * anything is a deck every renderer has to handle in general, and a renderer handling things in general is a
 * renderer that handles each of them badly.
 *
 * `Caveat` is the one worth explaining. A deck is read in a room, out loud, by somebody who may not have built
 * it — so the things the figures cannot say have to be **a slide**, not a footnote in six-point type that the
 * projector crops. It is placed where it cannot be skipped: immediately after the figures it qualifies.
 */
enum SlideKind: string
{
    case Title = 'title';

    /** A row of headline figures. The one slide somebody photographs. */
    case Scorecard = 'scorecard';

    case Trend = 'trend';

    /** A ranking, drawn as bars. */
    case Breakdown = 'breakdown';

    case Table = 'table';

    /** What the figures do not say. Its own slide, deliberately. */
    case Caveat = 'caveat';

    /** Prose: the assistant's findings, or the agency's own commentary. */
    case Commentary = 'commentary';

    public function label(): string
    {
        return match ($this) {
            self::Title => 'Title',
            self::Scorecard => 'Headline figures',
            self::Trend => 'Over time',
            self::Breakdown => 'Ranking',
            self::Table => 'Table',
            self::Caveat => 'What these figures do not say',
            self::Commentary => 'Commentary',
        };
    }

    /** Whether this slide draws a chart. Drives which renderer path is taken, and nothing else. */
    public function drawsChart(): bool
    {
        return $this === self::Trend || $this === self::Breakdown;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
