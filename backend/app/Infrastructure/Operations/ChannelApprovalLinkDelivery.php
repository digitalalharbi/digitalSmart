<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\Contract\ApprovalLinkDelivery;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp and email delivery of one-time approval links.
 *
 * No WhatsApp Business account or mail provider is configured for this product yet, so a live send is
 * `awaiting_credentials` — the link is recorded and audited, and nobody receives it. In local development only, the
 * link is written to the application log so the flow can be exercised end to end; it is never returned to a caller.
 */
final readonly class ChannelApprovalLinkDelivery implements ApprovalLinkDelivery
{
    public function __construct(private bool $logInDevelopment, private ?string $whatsAppToken, private ?string $mailer) {}

    public function deliver(string $channel, string $userId, string $url, string $title, string $expiresAt): string
    {
        $configured = $channel === 'whatsapp' ? $this->whatsAppToken !== null && $this->whatsAppToken !== '' : $this->mailer !== null && ! in_array($this->mailer, ['log', 'array'], true);

        if ($configured) {
            /* The provider send is the external handshake still to be built; until then nothing claims to have sent. */
            return 'awaiting_credentials';
        }

        if ($this->logInDevelopment) {
            Log::info('One-time approval link (development only)', ['channel' => $channel, 'user' => $userId, 'title' => $title, 'url' => $url, 'expires_at' => $expiresAt]);

            return 'logged';
        }

        return 'awaiting_credentials';
    }
}
