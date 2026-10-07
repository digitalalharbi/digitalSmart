<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;

/**
 * A version as it arrives, with whatever could be measured about it.
 *
 * The measurements are taken once here and stored, rather than recomputed per check, so a review shows
 * the same numbers a week later as it did on the day — and so a check can explain itself without
 * reopening a file that may since have been replaced.
 */
final readonly class VersionInput
{
    public function __construct(
        public AssetKind $kind,
        public AssetMeasurements $measurements,
        public ?string $label = null,
        public ?string $storagePath = null,
        /** For copy, which has no file. */
        public ?string $body = null,
    ) {}
}
