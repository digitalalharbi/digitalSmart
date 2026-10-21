<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * The figures a verdict about a creative is read from, for one period. Every one may be null: a platform that does not
 * report frequency, or a creative with no recorded results, has no such figure — which is not a zero.
 */
final readonly class CreativeFigures
{
    public function __construct(
        public ?float $impressions,
        public ?float $spend,
        public ?float $ctr,
        public ?float $frequency,
        public ?float $costPerResult,
        public ?float $results,
        public ?float $conversionRate,
    ) {}
}
