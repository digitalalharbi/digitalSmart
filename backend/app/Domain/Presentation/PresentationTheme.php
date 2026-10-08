<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

/**
 * How a deck looks, as tokens rather than as a design.
 *
 * **This is the abstraction the brief asks for first, and the reason it comes before any slide is drawn.** Smart
 * Code's own presentation identity has not been provided to this repository: no reference deck, no brand
 * guidelines, no palette. A product that guessed at one would have the guess baked into every renderer, every
 * slide layout and every exported file — and replacing it later would be a rewrite rather than a configuration
 * change.
 *
 * So a theme is a value object of tokens, every renderer consumes it, and nothing downstream knows a colour. The
 * default (`provisional()`) is derived from the product's own interface tokens and **says so**: it is a placeholder
 * that reads as deliberate rather than as a brand nobody chose.
 *
 * Two fonts, not one, and that is a requirement rather than a nicety. Arabic set in a Latin face is Arabic that
 * has been let down by whoever made the deck, and the product already ships IBM Plex Sans Arabic for exactly this
 * reason. A theme names both and the renderer picks by script.
 */
final readonly class PresentationTheme
{
    /**
     * @param  list<string>  $series
     */
    private function __construct(
        /** What to call this theme on screen. */
        public string $name,
        /** True while the identity is a placeholder rather than somebody's brand. */
        public bool $isProvisional,
        /** Slide width and height in points. 16:9 at 13.333in × 7.5in, which is what PowerPoint opens. */
        public float $slideWidthPt,
        public float $slideHeightPt,
        /** Hex, without the hash. Named by role, never by hue: a token called "blue" cannot be rebranded. */
        public string $canvas,
        public string $ink,
        public string $inkMuted,
        public string $accent,
        public string $accentContrast,
        public string $line,
        /** The Latin face, and the Arabic one. See the note above. */
        public string $latinFont,
        public string $arabicFont,
        /** Point sizes, largest first. A scale rather than a set of magic numbers in each layout. */
        public float $titleSize,
        public float $headingSize,
        public float $bodySize,
        public float $captionSize,
        /** Series colours, in order. Assigned by position and never cycled — see docs/DATA_VISUALISATION.md. */
        public array $series,
    ) {}

    /**
     * The placeholder identity.
     *
     * Derived from the product's own interface tokens, so a deck looks like the application that made it rather
     * than like a template nobody chose. It is labelled provisional everywhere it is used, because presenting a
     * guess as an agency's brand is worse than presenting an obvious placeholder.
     *
     * @param  list<string>|null  $series
     */
    public static function provisional(?array $series = null): self
    {
        return new self(
            name: 'digitalSmart (provisional)',
            isProvisional: true,
            /* 13.333in × 7.5in at 72pt to the inch: 16:9, and the size PowerPoint opens a new deck at. */
            slideWidthPt: 960.0,
            slideHeightPt: 540.0,
            canvas: 'FFFFFF',
            ink: '111827',
            inkMuted: '6B7280',
            accent: '2563EB',
            accentContrast: 'FFFFFF',
            line: 'E5E7EB',
            latinFont: 'Inter',
            arabicFont: 'IBM Plex Sans Arabic',
            titleSize: 40.0,
            headingSize: 24.0,
            bodySize: 14.0,
            captionSize: 10.0,
            /*
             * The measured categorical ramp the rest of the product uses, in the same order. A deck drawing the
             * same figures in different colours from the screen is a deck somebody has to reconcile by eye.
             */
            series: $series ?? ['2563EB', '059669', 'D97706', 'DC2626', '7C3AED', '0891B2'],
        );
    }

    /** The face for a script. The whole reason a theme carries two. */
    public function fontFor(bool $isArabic): string
    {
        return $isArabic ? $this->arabicFont : $this->latinFont;
    }

    /** A series colour by position, wrapping only once the ramp is exhausted. */
    public function seriesColour(int $index): string
    {
        $count = count($this->series);

        if ($count === 0) {
            return $this->accent;
        }

        /** @var string $colour */
        $colour = $this->series[$index % $count];

        return $colour;
    }
}
