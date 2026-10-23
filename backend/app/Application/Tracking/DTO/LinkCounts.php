<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use DateTimeImmutable;

/**
 * What a link brought, counting only traffic believed valid.
 *
 * Every figure the page has to *report* rather than this product *record* is nullable with a reason. This product
 * records the click itself, so clicks are always a real count (a link nobody clicked really has zero). A landing view
 * is reported by the destination page, so a link with clicks and no landing views is a page that is not reporting —
 * which is a different fact from "nobody arrived", and is stated as one.
 */
final readonly class LinkCounts
{
    public function __construct(
        public int $clicks,
        public int $visitors,
        public int $sessions,
        public ?int $landingViews,
        public ?string $landingViewsNote,
        /** Share of clicks that carried a documented click identifier; null with no clicks. */
        public ?float $clickIdCoverage,
        /** Events marked as likely automated or test, kept and excluded from every figure above. */
        public int $excluded,
        public ?DateTimeImmutable $lastClickAt,
    ) {}
}
