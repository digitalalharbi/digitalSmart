<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\ObservedEventRecord;
use App\Domain\Advertising\MetricSource;
use DateTimeImmutable;

/** Writing what a source was observed to report. */
interface ObservedEventWriter
{
    /**
     * Upsert a day's counts for one source.
     *
     * **Must replace rather than accumulate.** Every sync re-reads a restatement window, so the
     * same name and day arrive repeatedly; a writer that inserted would multiply a client's
     * conversions by the number of times the sync has run, and the figure would look like
     * growth.
     *
     * @param  list<ObservedEventRecord>  $records
     * @return int rows written
     */
    public function upsert(string $workspaceId, string $sourceId, array $records, MetricSource $source): int;

    /**
     * Record that a source was read, and when it last saw anything.
     *
     * Two separate timestamps because they answer different questions — "is this still working"
     * and "is it still measuring anything" — and the second is the one that catches a tag
     * somebody removed in a deploy.
     */
    public function markSynced(
        string $workspaceId,
        string $sourceId,
        DateTimeImmutable $syncedAt,
        ?DateTimeImmutable $lastEventAt,
    ): void;
}
