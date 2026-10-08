<?php

declare(strict_types=1);

namespace App\Application\Presentation\DTO;

/** One headline figure, formatted, with its change and where it came from. */
final readonly class SlideFigure
{
    public function __construct(
        public string $label,
        /** Already formatted, in major units, with its currency or its percentage sign. */
        public string $value,
        /**
         * The change against the comparison period, formatted. Null when there is none to show — which is not
         * the same as zero, and is never rendered as "0%".
         */
        public ?string $change,
        /** True when the change is good for this metric. Costs falling is good; spend falling is not. */
        public ?bool $changeIsGood,
        /** The source state, so a slide cannot present a sandbox figure as a client's spend. */
        public string $sourceLabel,
        /** Why the figure is absent, when it is. */
        public ?string $unavailableReason = null,
    ) {}
}
