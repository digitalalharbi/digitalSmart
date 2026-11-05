<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\MemberAddressBook;
use App\Application\Notifications\Contract\NotificationChannelDelivery;
use App\Application\Notifications\DTO\DeliveryOutcome;
use App\Application\Notifications\DTO\NotificationView;
use App\Application\Notifications\Mail\EmailKind;
use App\Application\Notifications\Mail\EmailRenderer;
use App\Application\Notifications\Mail\MailTransport;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\DigestChannel;

/**
 * A member's own email copy of a notification, through the one transport a message can leave by.
 *
 * Email only, and only when that transport is configured. In every other case — no provider, or WhatsApp, for which
 * there is still no adapter — the answer is the honest one it always was, from `HonestChannelDelivery`, so an
 * installation without credentials behaves exactly as before.
 *
 * The message goes to the member's own account address, to nobody else, and only while they are an active member of
 * the workspace it is about. `Sent` here means the mail server took it; the detail says arrival is not confirmed.
 */
final readonly class TransportChannelDelivery implements NotificationChannelDelivery
{
    public function __construct(
        private MailTransport $mail,
        private EmailRenderer $renderer,
        private MemberAddressBook $addresses,
        private NotificationChannelDelivery $otherwise,
        private string $frontendUrl,
    ) {}

    public function deliver(DigestChannel $channel, string $workspaceId, NotificationView $notification): DeliveryOutcome
    {
        if ($channel !== DigestChannel::Email || ! $this->mail->isConfigured()) {
            return $this->otherwise->deliver($channel, $workspaceId, $notification);
        }

        $address = $this->addresses->addressOf($workspaceId, $notification->recipientId);

        if ($address === null) {
            return new DeliveryOutcome(DeliveryStatus::Failed, 'Not sent: the recipient is no longer an active member of this workspace.');
        }

        $email = $this->renderer->render(
            EmailKind::NeedsAttention,
            $address['locale'],
            rtrim($this->frontendUrl, '/').$notification->path(),
            ['name' => $address['name'], 'subject' => $notification->title, 'detail' => $notification->body],
        );

        $result = $this->mail->send($address['email'], $address['name'], $email);

        return $result->accepted
            ? new DeliveryOutcome(DeliveryStatus::Sent, 'Accepted by the mail provider. Arrival in the inbox is not confirmed.')
            : new DeliveryOutcome(DeliveryStatus::Failed, $result->detail ?? 'The mail provider refused the message.');
    }
}
