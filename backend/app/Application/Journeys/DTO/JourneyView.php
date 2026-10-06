<?php

declare(strict_types=1);

namespace App\Application\Journeys\DTO;

use App\Domain\Journeys\Journey;

/**
 * A journey, with what is missing from it stated out loud.
 *
 * `limitations` is not decoration. A reader looking at one enquiry and one order has to know that the clicks,
 * sessions and ad impressions before them are absent rather than nonexistent — otherwise the obvious reading of
 * the page is that this customer arrived from nowhere, which is both wrong and actionable.
 */
final readonly class JourneyView
{
    /** @param list<string> $limitations */
    public function __construct(
        public Journey $journey,
        public array $limitations,
    ) {}
}
