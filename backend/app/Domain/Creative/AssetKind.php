<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/** What kind of thing a version is, which decides what can be measured about it. */
enum AssetKind: string
{
    case Image = 'image';
    case Video = 'video';
    /** Several images or videos in order. Checked per frame, and the set has its own count limit. */
    case Carousel = 'carousel';
    /** Headlines, primary text, descriptions. The only kind whose length is the whole specification. */
    case Copy = 'copy';

    /**
     * The requirements this kind can be checked against.
     *
     * A duration check on an image is not `Unchecked` — it is not applicable, and reporting it as
     * unchecked would fill a review with warnings nobody can act on.
     *
     * @return list<SpecRequirement>
     */
    public function measurable(): array
    {
        return match ($this) {
            self::Image => [
                SpecRequirement::Width,
                SpecRequirement::Height,
                SpecRequirement::AspectRatio,
                SpecRequirement::FileSize,
                SpecRequirement::Format,
            ],
            self::Video => [
                SpecRequirement::Width,
                SpecRequirement::Height,
                SpecRequirement::AspectRatio,
                SpecRequirement::FileSize,
                SpecRequirement::Duration,
                SpecRequirement::Format,
            ],
            self::Carousel => [SpecRequirement::FileSize, SpecRequirement::Format],
            self::Copy => [SpecRequirement::TextLength],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Carousel => 'Carousel',
            self::Copy => 'Copy',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
