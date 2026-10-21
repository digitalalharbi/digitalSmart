<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * A verdict, or none, and the figures it rests on.
 *
 * `evidence` is a list of named relative changes (`ctr_change` −0.31 is a click-through rate 31 % lower than the
 * period before) so a person can see why a creative was called what it was called, and disagree.
 */
final readonly class CreativeAssessment
{
    /** @param list<array{signal: string, value: float}> $evidence */
    public function __construct(
        public ?CreativeVerdict $verdict,
        public array $evidence = [],
    ) {}
}
