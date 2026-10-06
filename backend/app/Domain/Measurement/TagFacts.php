<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * The facts about one published tag that a readiness assessment needs.
 *
 * `name` and `type` are carried for *reporting* — so a finding can say which tag — and never as
 * grounds for one. A tag called "GA4 – Purchase" may fire on a thank-you page, on every page, or
 * never, and nothing about the string distinguishes those.
 */
final readonly class TagFacts
{
    /** @param list<string> $firingTriggerIds */
    public function __construct(
        public string $tagId,
        public string $name,
        /** The GTM template id, e.g. `gaawe`. Reported, never interpreted. */
        public string $type,
        public bool $isPaused = false,
        public array $firingTriggerIds = [],
    ) {}
}
