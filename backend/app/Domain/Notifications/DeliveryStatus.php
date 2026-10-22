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

    public function label(): string
    {
        return match ($this) {
            self::AwaitingCredentials => 'Awaiting credentials',
            self::Logged => 'Logged (development)',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
