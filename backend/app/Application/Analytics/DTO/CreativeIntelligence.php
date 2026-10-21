<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Shared\ValueObject\DateRange;

/** A page of creative rows, with what they were read for and whether there is more. */
final readonly class CreativeIntelligence
{
    /**
     * @param  list<CreativeRow>  $rows
     * @param  array<string, int>  $formats  creatives per format over the whole selection, not just this page
     * @param  array<string, int>  $platforms  creatives per platform over the whole selection
     */
    public function __construct(
        public string $grain,
        public DateRange $range,
        public DateRange $previousRange,
        public array $rows,
        public int $total,
        public bool $hasMore,
        public array $formats,
        public array $platforms,
    ) {}
}
