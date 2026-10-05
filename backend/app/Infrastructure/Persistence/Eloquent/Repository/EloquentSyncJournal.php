<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Advertising\Contract\SyncJournal;
use App\Application\Advertising\DTO\SyncContext;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\ProviderErrorCategory;
use App\Domain\Advertising\SyncKind;
use App\Domain\Advertising\SyncStatus;
use App\Infrastructure\Persistence\Eloquent\Model\ProviderError;
use App\Infrastructure\Persistence\Eloquent\Model\SyncCursor;
use App\Infrastructure\Persistence\Eloquent\Model\SyncRun;
use DateTimeImmutable;

final readonly class EloquentSyncJournal implements SyncJournal
{
    public function __construct(private Clock $clock) {}

    public function begin(
        SyncContext $context,
        SyncKind $kind,
        ?DateTimeImmutable $rangeStart = null,
        ?DateTimeImmutable $rangeEnd = null,
    ): string {
        $run = SyncRun::query()->create([
            'workspace_id' => $context->workspaceId,
            'provider_connection_id' => $context->providerConnectionId,
            'external_account_id' => $context->externalAccountId,
            'provider' => $context->provider->value,
            'kind' => $kind,
            'status' => SyncStatus::Running,
            'range_start' => $rangeStart?->format('Y-m-d'),
            'range_end' => $rangeEnd?->format('Y-m-d'),
            'started_at' => $this->clock->now(),
            // Recorded on the run itself, so a sandbox run is identifiable
            // forever rather than only while the connection stays sandbox.
            'metadata' => ['source' => $context->source->value],
        ]);

        return (string) $run->getKey();
    }

    public function complete(string $runId, SyncOutcome $outcome): void
    {
        $run = SyncRun::query()->find($runId);

        if ($run === null) {
            return;
        }

        $finishedAt = $this->clock->now();
        $startedAt = $run->started_at;

        $run->update([
            'status' => $outcome->status,
            'records_read' => $outcome->recordsRead,
            'records_written' => $outcome->recordsWritten,
            'api_calls' => $outcome->apiCalls,
            'finished_at' => $finishedAt,
            'duration_ms' => $startedAt instanceof DateTimeImmutable
                ? max(0, (int) round(($finishedAt->getTimestamp() - $startedAt->getTimestamp()) * 1000))
                : null,
            'error_message' => $outcome->failureMessage,
        ]);
    }

    public function recordError(
        string $runId,
        SyncContext $context,
        ProviderErrorCategory $category,
        string $message,
        ?int $statusCode = null,
        bool $retryable = false,
        ?int $retryAfterSeconds = null,
    ): void {
        ProviderError::query()->create([
            'workspace_id' => $context->workspaceId,
            'sync_run_id' => $runId,
            'provider' => $context->provider->value,
            'category' => $category,
            'status_code' => $statusCode,
            'message' => $message,
            'retryable' => $retryable,
            'retry_after_seconds' => $retryAfterSeconds,
            'context' => ['external_account_id' => $context->externalAccountId],
            'created_at' => $this->clock->now(),
        ]);
    }

    public function cursorFor(SyncContext $context, SyncKind $kind): ?string
    {
        $cursor = $this->cursorRow($context, $kind)?->cursor;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function rememberCursor(SyncContext $context, SyncKind $kind, ?string $cursor): void
    {
        /*
         * A null cursor means the last page was reached, and it is stored as
         * null so the next run starts over. Structure must be re-read from the
         * beginning each time: entities are renamed, paused and deleted, and a
         * cursor would make us blind to everything before it.
         */
        SyncCursor::query()->updateOrCreate(
            ['external_account_id' => $context->externalAccountId, 'kind' => $kind->value],
            [
                'workspace_id' => $context->workspaceId,
                'cursor' => $cursor,
                'last_success_at' => $this->clock->now(),
                'consecutive_failures' => 0,
            ],
        );
    }

    /** @return array{syncedThrough: ?DateTimeImmutable, resyncFrom: ?DateTimeImmutable} */
    public function watermarkFor(SyncContext $context, SyncKind $kind): array
    {
        $row = $this->cursorRow($context, $kind);

        return [
            'syncedThrough' => $row?->synced_through instanceof DateTimeImmutable ? $row->synced_through : null,
            'resyncFrom' => $row?->resync_from instanceof DateTimeImmutable ? $row->resync_from : null,
        ];
    }

    public function rememberWatermark(
        SyncContext $context,
        SyncKind $kind,
        DateTimeImmutable $syncedThrough,
        DateTimeImmutable $resyncFrom,
    ): void {
        SyncCursor::query()->updateOrCreate(
            ['external_account_id' => $context->externalAccountId, 'kind' => $kind->value],
            [
                'workspace_id' => $context->workspaceId,
                'synced_through' => $syncedThrough->format('Y-m-d'),
                'resync_from' => $resyncFrom->format('Y-m-d'),
                'last_success_at' => $this->clock->now(),
                'consecutive_failures' => 0,
            ],
        );
    }

    public function recordFailure(SyncContext $context, SyncKind $kind): void
    {
        $row = SyncCursor::query()->firstOrCreate(
            ['external_account_id' => $context->externalAccountId, 'kind' => $kind->value],
            ['workspace_id' => $context->workspaceId],
        );

        // Counted rather than overwritten: a connection failing for the ninth
        // time running is a different problem from one that failed once.
        $row->increment('consecutive_failures');
    }

    private function cursorRow(SyncContext $context, SyncKind $kind): ?SyncCursor
    {
        return SyncCursor::query()
            ->where('external_account_id', $context->externalAccountId)
            ->where('kind', $kind->value)
            ->first();
    }
}
