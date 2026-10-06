<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Domain\Integration\Provider;

/**
 * The record of every webhook that arrived.
 *
 * Written **before** anything is done with the payload, for three reasons that are all the same
 * reason: the request has to be answered in milliseconds, the work has to happen on a queue, and a
 * redelivery must not do the work twice.
 *
 * Providers redeliver on any non-2xx response. A redelivered order-paid event processed twice
 * doubles a day's revenue, and nothing downstream could tell that it had happened — so idempotency
 * is enforced by a unique index on the provider's own event id rather than by a check somebody
 * remembered to write.
 */
interface WebhookEventJournal
{
    /**
     * Record an arrival and say whether it is new.
     *
     * Returns null when this provider has already delivered this event id, which is the signal to
     * answer 200 and do nothing. An unverified signature is recorded too — the arrival is a fact
     * worth keeping — and is never queued.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null the event's id, or null when it is a redelivery
     */
    public function record(
        string $workspaceId,
        Provider $provider,
        string $topic,
        ?string $externalEventId,
        array $payload,
        bool $signatureVerified,
        ?string $storeId = null,
    ): ?string;

    /** Mark an event as queued for processing. */
    public function markQueued(string $eventId): void;

    public function markProcessed(string $eventId): void;

    /** Nothing to do with this topic, which is not a failure. */
    public function markIgnored(string $eventId, string $reason): void;

    public function markFailed(string $eventId, string $error): void;

    /** Refused outright: the signature did not verify, so the payload is not trusted. */
    public function markRejected(string $eventId, string $reason): void;
}
