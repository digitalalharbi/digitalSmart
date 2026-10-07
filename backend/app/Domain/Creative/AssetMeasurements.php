<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * What this product was able to measure about one asset.
 *
 * **Every field is nullable, and null means "not measured" rather than "zero".** That is the whole point:
 * a video uploaded through a path that could not read its duration has an unknown duration, and a spec
 * check that treated unknown as zero would report it as comfortably inside a minimum.
 *
 * Measured once on upload and stored, rather than recomputed per check, so a review shows the same
 * numbers a week later as it did on the day — and so a check can explain itself without reopening a file
 * that may since have been replaced.
 */
final readonly class AssetMeasurements
{
    public function __construct(
        public ?int $widthPx = null,
        public ?int $heightPx = null,
        public ?int $fileSizeBytes = null,
        public ?float $durationSeconds = null,
        /** The container or extension as read from the file, not as named by whoever uploaded it. */
        public ?string $format = null,
        /** For copy: the length that matters, which is characters rather than words. */
        public ?int $textLength = null,
        /** Why something is absent, where the product knows. Shown beside an `Unchecked` verdict. */
        public ?string $unmeasuredReason = null,
    ) {}

    public function aspectRatio(): ?float
    {
        if ($this->widthPx === null || $this->heightPx === null || $this->heightPx <= 0) {
            return null;
        }

        return $this->widthPx / $this->heightPx;
    }

    /** The ratio in the form a specification states, for showing beside the requirement. */
    public function describeRatio(): ?string
    {
        if ($this->widthPx === null || $this->heightPx === null || $this->heightPx <= 0) {
            return null;
        }

        $divisor = self::greatestCommonDivisor($this->widthPx, $this->heightPx);

        return sprintf('%d:%d', intdiv($this->widthPx, $divisor), intdiv($this->heightPx, $divisor));
    }

    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, abs($a));
    }
}
