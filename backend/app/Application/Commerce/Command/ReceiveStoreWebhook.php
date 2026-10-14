<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Commerce\Contract\StoreLocator;
use App\Application\Commerce\Contract\WebhookEventJournal;
use App\Application\Commerce\Contract\WebhookSignatureVerifier;
use App\Application\Commerce\DTO\WebhookReceipt;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;

/**
 * Take in a store webhook, decide whether to believe it, and write it down.
 *
 * Deliberately does almost nothing. The request has to be answered in milliseconds — a provider that
 * times out redelivers, and a slow handler turns one event into a queue of duplicates — so the only
 * work here is verifying the signature and recording the arrival. Everything that touches an order
 * happens on a queue afterwards.
 *
 * The order of operations is the security property:
 *
 *  1. find the store the payload names, which tells us whose secret to check against;
 *  2. verify the signature over the **raw** body;
 *  3. record the arrival, verified or not;
 *  4. only then say whether there is work to do.
 *
 * An unverified payload is recorded and never queued. Its arrival is a fact worth keeping — it is how
 * somebody finds out an endpoint is being probed — and the record of *which* it was is the only way to
 * be sure afterwards that nothing acted on it.
 */
final readonly class ReceiveStoreWebhook
{
    /**
     * The prefix of every topic this product acts on.
     *
     * Deliberately a prefix rather than a list of names. The platforms do not agree on their event
     * vocabulary — Salla sends `order.updated` and `order.status.updated`, Zid sends `order.create` and
     * `order.status.update` — and an explicit list written against one of them silently ignored every
     * event from the other. That is exactly how one provider's semantics get forced onto the next, and
     * it was found by the first Zid test that expected work to happen.
     *
     * Over-matching is safe here, and that is what makes the simpler rule the right one: the queued
     * worker refuses any payload that carries no order and marks it ignored, so a future `order.*` topic
     * this product does not understand costs one journal row and nothing else. Under-matching would cost
     * a client's orders.
     */
    private const string ACTIONABLE_PREFIX = 'order.';

    public function __construct(
        private StoreLocator $stores,
        private WebhookEventJournal $journal,
        private ProviderConnectionRepository $connections,
        private WebhookSignatureVerifier $verifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the decoded body, for routing only
     * @param  string  $rawBody  the body exactly as it arrived — what a signature covers
     * @param  array<string, string|null>  $headers  lower-cased header names; each provider takes what
     *                                               its own documentation says to take
     */
    public function __invoke(
        Provider $provider,
        array $payload,
        string $rawBody,
        array $headers,
    ): WebhookReceipt {
        $topic = $this->topicOf($payload);
        $merchantId = $this->merchantOf($payload);

        $candidates = $merchantId === null ? [] : $this->stores->findAllByExternalId($provider, $merchantId);

        if ($candidates === []) {
            /*
             * A webhook for a store nobody has registered. Answered 200 and dropped rather than
             * recorded: without a workspace there is nowhere to record it, and refusing would make the
             * provider redeliver forever. This is the one case that leaves no trace, which is why the
             * response says so plainly.
             */
            return WebhookReceipt::unknownStore($topic);
        }

        /*
         * Two workspaces can legitimately register the same merchant — two agencies managing one shop —
         * and each will have installed its own app with its own secret. So the signature is what
         * identifies *whose* delivery this is: it is checked against each candidate's own secret, and
         * the one that verifies is the one that gets the order. Taking the first candidate instead
         * would route one agency's client's orders into the other's workspace, which nothing
         * downstream could detect.
         */
        $store = $candidates[0];
        $verified = false;

        foreach ($candidates as $candidate) {
            $connection = $this->connections->agencyConnection(new WorkspaceId($candidate->workspaceId), $provider);

            if ($this->verifier->verify($provider, $rawBody, $headers, $connection?->credentials()->get('webhook_secret'))) {
                $store = $candidate;
                $verified = true;

                break;
            }
        }

        $eventId = $this->journal->record(
            workspaceId: $store->workspaceId,
            provider: $provider,
            topic: $topic,
            // Salla sends no event id. Idempotency for it comes from the upsert, not from this.
            externalEventId: $this->eventIdOf($payload),
            payload: $payload,
            signatureVerified: $verified,
            storeId: $store->id,
        );

        if ($eventId === null) {
            // Already delivered. Answered 200 so the provider stops, and nothing is done twice.
            return WebhookReceipt::duplicate($topic);
        }

        if (! $verified) {
            $this->journal->markRejected($eventId, 'The signature did not verify.');

            return WebhookReceipt::rejected($topic, $eventId);
        }

        if (! str_starts_with($topic, self::ACTIONABLE_PREFIX)) {
            $this->journal->markIgnored($eventId, 'This product does not act on this topic.');

            return WebhookReceipt::ignored($topic, $eventId);
        }

        $this->journal->markQueued($eventId);

        return WebhookReceipt::queued($topic, $eventId, $store->id, $store->workspaceId);
    }

    /** @param array<string, mixed> $payload */
    private function topicOf(array $payload): string
    {
        $event = $payload['event'] ?? null;

        return is_string($event) && $event !== '' ? $event : 'unknown';
    }

    /** @param array<string, mixed> $payload */
    private function merchantOf(array $payload): ?string
    {
        $merchant = $payload['merchant'] ?? null;

        if (is_int($merchant) || is_float($merchant)) {
            return (string) (int) $merchant;
        }

        if (is_string($merchant) && $merchant !== '') {
            return $merchant;
        }

        // Some payloads nest it under data.
        $data = $payload['data'] ?? null;

        if (is_array($data)) {
            $nested = $data['store_id'] ?? $data['merchant'] ?? null;

            if (is_int($nested) || is_float($nested)) {
                return (string) (int) $nested;
            }

            if (is_string($nested) && $nested !== '') {
                return $nested;
            }
        }

        return null;
    }

    /**
     * The provider's own event id, where it sends one.
     *
     * Salla's envelope is `{event, merchant, created_at, data}` with no id, so this is usually null —
     * and that is why the commerce writes are upserts keyed on the order's own id. Idempotency by data
     * is stronger than idempotency by an id the provider does not send, and this product will not
     * invent one from a payload hash: two genuinely distinct events can hash identically, and silently
     * dropping the second would lose a real sale.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventIdOf(array $payload): ?string
    {
        foreach (['event_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_int($value) || is_float($value)) {
                return (string) (int) $value;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
