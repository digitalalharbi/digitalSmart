<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Commerce\Command\ReadStore;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reads one store on the queue.
 *
 * Unique per store, because two concurrent reads of the same merchant race on the same upsert keys: the
 * database keeps one row either way, but the API calls are spent against a rate limit that matters — and both
 * Salla and Zid page sequentially, so a second walk interleaved with the first reads from a cache it poisoned.
 *
 * Backed off rather than retried immediately, for the same reason as the advertising job: a tight retry loop is
 * how a connection gets throttled for an hour instead of a minute.
 */
final class ReadStoreJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** Long enough to walk a busy store's pages, short enough to notice a hang. */
    public int $timeout = 600;

    public function __construct(
        private readonly string $workspaceId,
        private readonly string $storeId,
        private readonly ?string $since = null,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->storeId;
    }

    public function handle(ReadStore $read): void
    {
        $result = $read(
            new WorkspaceId($this->workspaceId),
            $this->storeId,
            /* No actor: a scheduled read belongs to the system, not to whoever last signed in. */
            null,
            $this->since === null ? null : new DateTimeImmutable($this->since),
        );

        if ($result->succeeded()) {
            return;
        }

        /*
         * The audit trail already carries the outcome, which is what the product reads. This line is for the
         * operator tailing a worker, and deliberately does not re-throw on a partial: the orders that landed
         * are real, and a retry would re-read what already worked.
         */
        Log::warning('Store read did not complete', [
            'workspace_id' => $this->workspaceId,
            'store_id' => $this->storeId,
            'status' => $result->status->value,
            'orders' => $result->ordersWritten,
            'failure' => $result->failureMessage,
        ]);
    }

    /** Surface a transport failure to the queue so its backoff applies. */
    public function failed(ProviderRequestFailed $exception): void
    {
        Log::error('Store read failed against the provider', [
            'workspace_id' => $this->workspaceId,
            'store_id' => $this->storeId,
            'provider' => $exception->provider->value,
            'retryable' => $exception->isRetryable(),
        ]);
    }
}
