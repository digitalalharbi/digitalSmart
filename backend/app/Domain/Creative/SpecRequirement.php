<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * The properties of an asset a placement's specification can constrain.
 *
 * A closed list, because each member needs code that can actually measure it. Adding one means adding
 * the measurement; a requirement nobody can measure would report `Unchecked` for ever, which is honest
 * but useless.
 */
enum SpecRequirement: string
{
    case Width = 'width';
    case Height = 'height';
    /** Expressed as a ratio with a tolerance, not an exact float: 1080×1920 is 9:16 and 1079×1920 is not. */
    case AspectRatio = 'aspect_ratio';
    case FileSize = 'file_size';
    case Duration = 'duration';
    case Format = 'format';
    /** Headline, primary text, description — whichever the platform limits. */
    case TextLength = 'text_length';

    public function label(): string
    {
        return match ($this) {
            self::Width => 'Width',
            self::Height => 'Height',
            self::AspectRatio => 'Aspect ratio',
            self::FileSize => 'File size',
            self::Duration => 'Duration',
            self::Format => 'File format',
            self::TextLength => 'Text length',
        };
    }

    /** The unit a reader needs to make sense of the number. */
    public function unit(): ?string
    {
        return match ($this) {
            self::Width, self::Height => 'px',
            self::FileSize => 'bytes',
            self::Duration => 'seconds',
            self::TextLength => 'characters',
            self::AspectRatio, self::Format => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
