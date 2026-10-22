<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\NotificationChannelDelivery;
use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\DigestChannel;
use Illuminate\Support\Facades\Log;

/**
 * The email and WhatsApp adapters, in the state they are honestly in.
 *
 * Neither sends. Email needs a sender identity and a mail provider this repository does not hold; WhatsApp needs a
 * Business API token and a template approved by Meta. Each answer names exactly that, so an operator reading the
 * delivery history knows what to supply — and a development checkout writes the message to its log, labelled as
 * such, so the shape of what would be sent can be read without a provider.
 *
 * When a provider is connected, this class is replaced by one that returns `sent` with the provider's own
 * acknowledgement. Nothing here may be edited to return `sent` without one.
 */
final readonly class HonestChannelDelivery implements NotificationChannelDelivery
{
    public function __construct(private bool $logInDevelopment) {}

    public function deliver(DigestChannel $channel, string $workspaceId, NotificationView $notification): DeliveryOutcome
    {
        if ($this->logInDevelopment) {
            Log::info('Notification delivery (development only — nothing was sent)', [
                'channel' => $channel->value,
                'workspace' => $workspaceId,
                'recipient' => $notification->recipientId,
                'title' => $notification->title,
            ]);

            return new DeliveryOutcome(DeliveryStatus::Logged, 'Written to the application log; nothing was sent.');
        }

        return new DeliveryOutcome(DeliveryStatus::AwaitingCredentials, match ($channel) {
            DigestChannel::Email => 'No mail provider and no sender identity are configured. Set MAIL_MAILER and MAIL_FROM_ADDRESS on the server, then connect a mail adapter.',
            DigestChannel::WhatsApp => 'No WhatsApp Business API token is configured, and no message template has been approved.',
            DigestChannel::InApp => 'In-app notifications are the bell itself and need no delivery.',
        });
    }
}
