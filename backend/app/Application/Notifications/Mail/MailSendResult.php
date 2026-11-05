<?php

declare(strict_types=1);

namespace App\Application\Notifications\Mail;

/**
 * What the provider said when handed one message.
 *
 * `accepted` means the mail server took it — not that it arrived. Delivery, bounce and complaint are the provider's
 * later knowledge.
 */
final readonly class MailSendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $messageId = null,
        public ?string $detail = null,
    ) {}
}
