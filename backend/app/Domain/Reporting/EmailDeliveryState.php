<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * What happened to one scheduled email, in words that claim no more than is known.
 *
 * `ProviderAccepted` is the strongest thing this product can say by itself: the mail server took the message. Whether
 * it reached an inbox is the provider's knowledge, reported through a webhook this installation does not have yet, so
 * there is deliberately no "delivered" here — a state nothing can write would be a state somebody reads as true.
 */
enum EmailDeliveryState: string
{
    /** Claimed for this period; the send has not finished. A crash leaves it here, and the next run retries it. */
    case Pending = 'pending';

    case ProviderAccepted = 'provider_accepted';

    case Failed = 'failed';

    /** No mail provider is configured. Nothing was sent and no link was made. */
    case AwaitingCredentials = 'awaiting_credentials';

    /** Not attempted, with the reason: the report or the sender's access to it is gone. */
    case Skipped = 'skipped';

    /** Whether a later run may try this period again. */
    public function mayRetry(): bool
    {
        return $this === self::Pending || $this === self::Failed || $this === self::AwaitingCredentials;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
