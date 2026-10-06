<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

/**
 * What the measurement sources have actually been seen to report.
 *
 * Separate from the repositories because it answers a different question with a different
 * authority: they own what somebody declared, this owns what arrived.
 */
interface ObservedEventReadModel
{
    /**
     * Counts per raw event name for a set of sources, over a trailing window.
     *
     * @param  list<string>  $sourceIds
     * @return array<string, array{count: int, has_value: bool, last_date: string}> keyed by raw name
     */
    public function countsByRawName(string $workspaceId, array $sourceIds, int $days): array;

    /**
     * Raw names that have been seen but are mapped to nothing.
     *
     * The honest centrepiece of the health check: an event arriving and being counted as
     * nothing is invisible everywhere else in the product.
     *
     * @param  list<string>  $sourceIds
     * @return list<string>
     */
    public function unmappedRawNames(string $workspaceId, array $sourceIds, int $days): array;
}
