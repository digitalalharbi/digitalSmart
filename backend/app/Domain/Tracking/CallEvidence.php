<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use DateTimeImmutable;

/**
 * What a call-tracking provider says about one phone call.
 *
 * A call is evidence only when a provider that carries calls reports it: a number dialled from a page proves that a button
 * was pressed, not that anybody spoke. So this exists as a contract for a future provider and nothing creates it here —
 * no provider is connected, and nothing infers a call from a click. The caller's number is deliberately not a field: it
 * would be personal data this product has no reason to hold in an event, and a lead made from a call takes it from the
 * provider's own record when a person asks for that.
 */
final readonly class CallEvidence
{
    public function __construct(
        public string $provider,
        public string $providerCallId,
        public DateTimeImmutable $startedAt,
        public int $durationSeconds,
        public bool $answered,
        public ?string $trackingNumberId,
        public ?string $touchpoint,
    ) {}

    /** A call of a few seconds is a hang-up; what counts as a conversation is the project's decision, not ours. */
    public function wasAnswered(): bool
    {
        return $this->answered && $this->durationSeconds > 0;
    }
}
