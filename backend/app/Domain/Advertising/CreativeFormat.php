<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

enum CreativeFormat: string
{
    case Image = 'image';
    case Video = 'video';
    case Carousel = 'carousel';
    case Collection = 'collection';
    case Text = 'text';
    case ResponsiveSearch = 'responsive_search';
    case ResponsiveDisplay = 'responsive_display';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Carousel => 'Carousel',
            self::Collection => 'Collection',
            self::Text => 'Text',
            self::ResponsiveSearch => 'Responsive search',
            self::ResponsiveDisplay => 'Responsive display',
            self::Unknown => 'Unknown',
        };
    }

    public function hasVisual(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Carousel, self::Collection, self::ResponsiveDisplay], true);
    }
}
