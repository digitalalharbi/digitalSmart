<?php

declare(strict_types=1);

namespace App\Application\Presentation\DTO;

use App\Domain\Presentation\SlideKind;

/**
 * One slide, with every figure already formatted.
 *
 * **Nothing downstream does arithmetic, and nothing downstream formats a number.** Two renderers — a PPTX writer
 * and a page in a browser — read this object, and a figure formatted independently in each would eventually
 * differ: a different rounding, a different thousands separator, a currency in minor units in one of them. The
 * composer formats once, both render the same string, and a deck matches the screen it came from.
 *
 * `series` carries raw values as well, because a chart needs numbers. They are the same numbers the formatted
 * strings were made from, and no renderer is permitted to label a point with anything but the string.
 */
final readonly class Slide
{
    /**
     * @param  list<SlideFigure>  $figures
     * @param  list<SlideSeries>  $series
     * @param  list<string>  $categories  the x-axis, shared by every series on the slide
     * @param  list<string>  $notes  caveats and source statements, rendered where they cannot be skipped
     * @param  list<string>  $paragraphs  prose, for a commentary slide
     */
    public function __construct(
        public SlideKind $kind,
        public string $title,
        /** One sentence under the title. Null when the title says it all. */
        public ?string $lead,
        public array $figures = [],
        public array $series = [],
        public array $categories = [],
        public ?SlideTable $table = null,
        public array $notes = [],
        public array $paragraphs = [],
    ) {}
}
