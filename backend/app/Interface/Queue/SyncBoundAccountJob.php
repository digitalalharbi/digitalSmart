<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Advertising\Command\SyncBoundAccount;
use App\Application\Integration\Exception\ProviderRequestFailed;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Syncs one external account on the queue.
 *
 * Unique per account, because two concurrent syncs of the same account would
 * race on the same upsert keys; the database would keep one row either way, but
 * the API calls would be wasted against a rate limit that matters.
 *
 * Retries are backed off, not immediate. Every provider here rate-limits by
 * account, and a tight retry loop is how a connection gets throttled for an
 * hour rather than a minute.
 */
final class SyncBoundAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** Long enough for a wide insight range, short enough to notice a hang. */
    public int $timeout = 600;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $externalAccountId,
        private readonly ?string $since = null,
        private readonly ?string $until = null,
        /*
         * Which of the four sync queues this waits in. The planner decides: recovery, live, low or
         * backfill. Default is the live queue, which is what a manual dispatch means.
         */
        string $queue = 'sync',
    ) {
        $this->onQueue($queue);
    }

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->externalAccountId;
    }

    public function handle(SyncBoundAccount $sync): void
    {
        $outcome = $sync(
            $this->workspaceId,
            $this->externalAccountId,
            $this->since === null ? null : new DateTimeImmutable($this->since),
            $this->until === null ? null : new DateTimeImmutable($this->until),
        );

        if ($outcome->succeeded()) {
            return;
        }

        /*
         * The run already recorded itself in the journal, which is what the
         * product reads. This log line exists for the operator tailing a worker,
         * and deliberately does not re-throw on a partial: the rows that landed
         * are real, and a retry would re-read what already succeeded.
         */
        Log::warning('Account sync did not complete', [
            'workspace_id' => $this->workspaceId,
            'external_account_id' => $this->externalAccountId,
            'status' => $outcome->status->value,
            'records_written' => $outcome->recordsWritten,
            'failed_kind' => $outcome->failedKind?->value,
        ]);
    }

    /** Surface a transport failure to the queue so its backoff applies. */
    public function failed(ProviderRequestFailed $exception): void
    {
        Log::error('Account sync failed against the provider', [
            'workspace_id' => $this->workspaceId,
            'external_account_id' => $this->externalAccountId,
            'provider' => $exception->provider->value,
            'retryable' => $exception->isRetryable(),
        ]);
    }
}
