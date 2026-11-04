<?php

declare(strict_types=1);

namespace App\Application\Notifications\Mail;

/**
 * Writes a message. It does not send one: this product has no mail provider, and delivery is recorded as
 * `awaiting_credentials` until one exists (`NotificationChannelDelivery`).
 */
interface EmailRenderer
{
    /**
     * @param  'ar'|'en'  $locale
     * @param  string  $url  Where the one button goes. https only (http in local development).
     * @param  array{name?: ?string, subject?: ?string, detail?: ?string}  $parts  The recipient's name, the thing the message is about (a workspace, a report) and one extra line. Every value is escaped.
     */
    public function render(EmailKind $kind, string $locale, string $url, array $parts = []): RenderedEmail;
}
