<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Commerce\Contract\CommerceStore;
use App\Application\Commerce\Contract\StoreOrderMapperRegistry;
use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Commerce\Contract\WebhookEventReader;
use App\Domain\Advertising\MetricSource;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Integration\Provider;

/**
 * Applies what a webhook said, off the request.
 *
 * Reads the payload that was already recorded rather than being handed one. That matters: the journal
 * row is the only thing that has been through the signature check, so taking the payload from
 * anywhere else would mean the work could be driven by something unverified. A row that was not
 * verified is refused here too, belt and braces, because this class can be reached from a queue that
 * somebody could enqueue to directly.
 *
 * **The work itself is an upsert of the order the payload describes**, which is what makes a
 * redelivery harmless even for a provider like Salla that sends no event id. Idempotency by data is
 * stronger than idempotency by an identifier the provider does not send.
 *
 * A webhook is also deliberately **not** trusted as the whole truth. It carries the order as it was at
 * that moment; the reconciliation sync re-reads a window and corrects anything a dropped delivery
 * missed. Neither mechanism alone is enough, and they are designed to produce the same rows.
 */
final readonly class ProcessStoreWebhook
{
    public function __construct(
        private WebhookEventReader $events,
        private WebhookEventJournal $journal,
        private StoreOrderMapperRegistry $mappers,
        private CommerceStore $store,
    ) {}

    public function __invoke(string $eventId): void
    {
        $event = $this->events->find($eventId);

        if ($event === null) {
            // Nothing to do. An event that has been deleted is not a failure to report.
            return;
        }

        if (! $event->signatureVerified) {
            /*
             * Should be unreachable through the endpoint, which never queues an unverified row. Checked
             * anyway: this is the last gate before a payload becomes a client's revenue figure, and a
             * job can be enqueued by anything with queue access.
             */
            $this->journal->markRejected($eventId, 'Refused: the signature had not verified.');

            return;
        }

        if ($event->storeId === null) {
            $this->journal->markIgnored($eventId, 'No store on the event, so there is nothing to write to.');

            return;
        }

        $orderPayload = $this->orderPayloadOf($event->payload);

        if ($orderPayload === null) {
            /*
             * Topics that carry no order — a shipment update whose payload names only a tracking number,
             * for instance. Ignored rather than failed: the reconciliation sync will pick the order up
             * with its new state, and a wall of `failed` rows would hide the real failures.
             */
            $this->journal->markIgnored($eventId, 'The payload carried no order to apply.');

            return;
        }

        $order = $this->mappers->for($event->provider)->order($orderPayload);

        if ($order->externalId === '') {
            $this->journal->markIgnored($eventId, 'The payload carried no order id.');

            return;
        }

        $this->store->upsertOrders(
            $event->workspaceId,
            $event->storeId,
            [$order],
            $this->revenueSourceFor($event->provider),
            /*
             * A webhook is a live statement from the provider, so `Live` rather than `Synced`: it is the
             * freshest thing this product can have, and a reader deserves to see that a figure arrived
             * this way rather than from a nightly read.
             */
            MetricSource::Live,
        );

        /*
         * The customer's lifetime figures change with the order, and leaving them until the next full
         * sync would mean a client's best customer looked smaller than their orders for a day.
         */
        $this->store->recalculateCustomerTotals($event->workspaceId, $event->storeId);

        $this->journal->markProcessed($eventId);
    }

    /** Record a failure the queue could not recover from. */
    public function recordFailure(string $eventId, string $message): void
    {
        $this->journal->markFailed($eventId, $message);
    }

    /**
     * The order inside a webhook envelope.
     *
     * Salla's envelope is `{event, merchant, created_at, data}`, and for order topics `data` *is* the
     * order. Some topics nest it one deeper under `data.order`, so both are accepted — and anything
     * else returns null rather than being coerced, because an envelope this product does not recognise
     * is not one it should guess the shape of.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function orderPayloadOf(array $payload): ?array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $nested = $data['order'] ?? null;

        if (is_array($nested) && isset($nested['id'])) {
            /** @var array<string, mixed> $nested */
            return $nested;
        }

        return isset($data['id']) ? $data : null;
    }

    private function revenueSourceFor(Provider $provider): RevenueSource
    {
        return match ($provider) {
            Provider::Salla => RevenueSource::Salla,
            Provider::Zid => RevenueSource::Zid,
            default => RevenueSource::Manual,
        };
    }
}
