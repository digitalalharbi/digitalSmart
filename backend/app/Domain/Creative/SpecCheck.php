<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * One property of one asset, checked against one recorded requirement.
 *
 * Carries the measurement and the requirement as well as the verdict, so an interface can say "1080×1350
 * against a minimum of 1080×1920" rather than "fails aspect ratio". A reader who can see both numbers
 * fixes the file; a reader who sees a red tick asks somebody.
 */
final readonly class SpecCheck
{
    public function __construct(
        public SpecRequirement $requirement,
        public SpecVerdict $verdict,
        /** What the asset actually is, where it could be measured. */
        public ?string $measured = null,
        /** What the specification asks for. */
        public ?string $expected = null,
        /** Why the verdict is what it is, in one sentence, where it needs one. */
        public ?string $detail = null,
    ) {}

    /** A requirement the product cannot measure for this asset. Never reported as a pass. */
    public static function unchecked(SpecRequirement $requirement, string $expected, string $why): self
    {
        return new self($requirement, SpecVerdict::Unchecked, null, $expected, $why);
    }
}
