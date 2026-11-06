<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * What became of one attempt to deliver a notification over one channel.
 *
 * `awaiting_credentials` is the state every email and WhatsApp delivery is in today, and it is a state rather than a
 * failure: the adapter is built, the provider is not connected, and the row says so instead of pretending. `logged`
 * is a development checkout writing the message to its log. `sent` is written only by an adapter that had a
 * provider's acknowledgement in hand; nothing in this repository can produce it yet.
 */
enum DeliveryStatus: string
{
    case AwaitingCredentials = 'awaiting_credentials';
    case Logged = 'logged';
    case Sent = 'sent';
    case Failed = 'failed';

    /** Not attempted: the recipient was inside their quiet hours or had snoozed. It is in the bell; it is not sent later. */
    case Held = 'held';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingCredentials => 'Awaiting credentials',
            self::Logged => 'Logged (development)',
            // The mail server took it. Arrival is the provider's later knowledge, and nothing here claims it.
            self::Sent => 'Accepted by the provider',
            self::Failed => 'Failed',
            self::Held => 'Held (quiet hours or snoozed)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
