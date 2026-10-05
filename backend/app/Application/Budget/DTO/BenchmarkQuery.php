<?php

declare(strict_types=1);

namespace App\Application\Budget\DTO;

/**
 * Which of a workspace's own delivery to derive rates from.
 *
 * There is no industry-benchmark option, and that is the point: a rate this product
 * cannot trace to spend somebody in this workspace actually made is a rate it will not
 * state. A client asking "where does this estimate come from" gets an answer.
 */
final readonly class BenchmarkQuery
{
    /** @param list<string> $providers empty means every provider with data */
    public function __construct(
        public array $providers = [],
        /** Trailing window. Ninety days is a quarter of behaviour without being stale. */
        public int $days = 90,
        public ?string $clientId = null,
        public ?string $projectId = null,
    ) {}
}
