<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Shared\Contract\Clock;
use App\Domain\Integration\Provider;
use App\Infrastructure\Persistence\Eloquent\Model\StoreWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records every webhook that arrives, once.
 *
 * The idempotency is the unique index, not a check. A check-then-insert can lose a race, and a
 * provider redelivering an order-paid event twice in the same second is not hypothetical — it is
 * what happens when the first delivery times out while succeeding.
 *
 * The insert asks the database to ignore a conflict rather than catching the violation afterwards.
 * That is not a style preference: in PostgreSQL a constraint violation aborts the whole surrounding
 * transaction, so catching one would turn a harmless redelivery into a failed request for any caller
 * that happened to be inside a transaction — and a webhook handler that also writes an order will
 * be.
 */
final readonly class EloquentWebhookEventJournal implements WebhookEventJournal
{
    public function __construct(private Clock $clock) {}

    /** @param array<string, mixed> $payload */
    public function record(
        string $workspaceId,
        Provider $provider,
        string $topic,
        ?string $externalEventId,
        array $payload,
        bool $signatureVerified,
        ?string $storeId = null,
    ): ?string {
        /*
         * A provider that sends no event id cannot be deduplicated by one, so the row is stored with
         * a generated id and processed. That is the honest trade: this product will not invent an
         * identity from a payload hash, because two genuinely distinct events — two items of the same
         * product added a second apart — can hash identically, and silently dropping the second
         * would lose a real sale.
         */
        $id = (string) Str::uuid7();
        $now = $this->clock->now();

        $inserted = DB::table('store_webhook_events')->insertOrIgnore([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'store_id' => $storeId,
            'provider' => $provider->value,
            'topic' => $topic,
            'external_event_id' => $externalEventId,
            'signature_verified' => $signatureVerified,
            /*
             * An unverified payload is `rejected` on arrival, never `received`. The state it is
             * written in is the state it stays in, so there is no window in which something could
             * pick it up as work.
             */
            'status' => $signatureVerified ? 'received' : 'rejected',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /*
         * Zero rows means the unique index already held this provider's event id. The caller answers
         * 200 and does nothing, which is what stops a redelivery doubling a day's revenue.
         */
        return $inserted === 1 ? $id : null;
    }

    public function markQueued(string $eventId): void
    {
        $this->transition($eventId, 'queued');
    }

    public function markProcessed(string $eventId): void
    {
        $this->transition($eventId, 'processed', processed: true);
    }

    public function markIgnored(string $eventId, string $reason): void
    {
        // Not a failure. A topic this product does not act on is a normal thing to receive.
        $this->transition($eventId, 'ignored', error: $reason, processed: true);
    }

    public function markFailed(string $eventId, string $error): void
    {
        $this->transition($eventId, 'failed', error: $error);
    }

    public function markRejected(string $eventId, string $reason): void
    {
        $this->transition($eventId, 'rejected', error: $reason);
    }

    private function transition(
        string $eventId,
        string $status,
        ?string $error = null,
        bool $processed = false,
    ): void {
        StoreWebhookEvent::query()->whereKey($eventId)->update([
            'status' => $status,
            'error' => $error,
            'processed_at' => $processed ? $this->clock->now() : null,
        ]);
    }
}
