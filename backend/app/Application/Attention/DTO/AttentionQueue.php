<?php

declare(strict_types=1);

namespace App\Application\Attention\DTO;

use App\Domain\Attention\AttentionStream;
use DateTimeImmutable;

/** Everything waiting on a person, with an honest account of what was looked at to find it. */
final readonly class AttentionQueue
{
    /**
     * @param  list<AttentionItem>  $items  worst first, then oldest
     * @param  array<string, int>  $severityCounts  every severity present, so a zero reads as "none"
     * @param  list<AttentionStream>  $covered  streams the reader may see, and which were consulted
     * @param  list<AttentionStream>  $failed  streams that were consulted and could not be read
     */
    public function __construct(
        public array $items,
        public array $severityCounts,
        public array $covered,
        public array $failed,
        public int $total,
        public DateTimeImmutable $asOf,
    ) {}
}
