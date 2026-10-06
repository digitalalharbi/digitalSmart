<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Measurement\Contract\ObservedEventWriter;
use App\Application\Measurement\DTO\ObservedEventRecord;
use App\Domain\Advertising\MetricSource;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Writes observed counts, replacing rather than accumulating. */
final readonly class EloquentObservedEventWriter implements ObservedEventWriter
{
    /**
     * Rows per statement.
     *
     * A fortnight of a busy property is a few hundred rows, which one statement handles; a year
     * backfilled across a large event vocabulary is not, and a single enormous upsert is how a
     * sync holds a lock long enough to matter.
     */
    private const int CHUNK = 500;

    /** @param list<ObservedEventRecord> $records */
    public function upsert(string $workspaceId, string $sourceId, array $records, MetricSource $source): int
    {
        if ($records === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($records as $record) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'measurement_source_id' => $sourceId,
                'raw_event_name' => $record->rawEventName,
                'date' => $record->date,
                'event_count' => $record->count,
                'value_minor' => $record->valueMinor,
                'currency' => $record->currency,
                /*
                 * The honesty label, carried on every row rather than on the run. A query that
                 * spans a sampled day and a complete one has to be able to see that from the
                 * rows it touched, without joining back to a sync journal.
                 */
                'source' => $source->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $written = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            /*
             * Upsert on the natural key the unique index already enforces. Replacement, not
             * accumulation: every sync re-reads a restatement window, so the same name and day
             * arrive repeatedly, and inserting would multiply a client's conversions by the
             * number of times the sync has run — which would look exactly like growth.
             *
             * `id` is deliberately absent from the update list. A row keeps the identity it was
             * first written with, so anything referencing it still resolves.
             */
            DB::table('observed_event_counts')->upsert(
                $chunk,
                ['measurement_source_id', 'raw_event_name', 'date'],
                ['event_count', 'value_minor', 'currency', 'source', 'updated_at'],
            );

            $written += count($chunk);
        }

        return $written;
    }

    public function markSynced(
        string $workspaceId,
        string $sourceId,
        DateTimeImmutable $syncedAt,
        ?DateTimeImmutable $lastEventAt,
    ): void {
        $attributes = ['last_synced_at' => $syncedAt];

        if ($lastEventAt !== null) {
            /*
             * Moved forward only, in SQL rather than by reading first and writing after.
             *
             * Two reasons. A run that read an empty window passes null and must not erase the
             * last event we know about — that would turn a quiet weekend into "never reported
             * anything", which is a much louder claim. And a backfill of an older range sees
             * genuine events with older dates; letting those win would age a healthy source into
             * a critical warning. `greatest` settles both without a read-modify-write that two
             * concurrent syncs could interleave.
             */
            $attributes['last_event_at'] = DB::raw(sprintf(
                "greatest(coalesce(last_event_at, '-infinity'::timestamp), '%s'::timestamp)",
                $lastEventAt->format('Y-m-d H:i:s'),
            ));
        }

        MeasurementSource::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($sourceId)
            ->update($attributes);
    }
}
