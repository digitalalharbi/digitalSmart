<?php

declare(strict_types=1);

namespace App\Application\Notifications\Mail;

/**
 * Hands a rendered email to a real mail provider — or says, truthfully, that there is none.
 *
 * `isConfigured()` is the gate every caller asks first. An installation with no provider, no sender identity or the
 * delivery switch off answers false, and a caller records "awaiting credentials" rather than calling `send()`: an
 * email written to a log file is not an email, and nothing here may let it be recorded as one.
 */
interface MailTransport
{
    public function isConfigured(): bool;

    /** What is missing, in words an operator can act on. Empty when configured. */
    public function missing(): string;

    /**
     * Never throws: a refusal by the provider is an outcome to record, not an exception to lose.
     *
     * @param  array<string, string>  $headers  extra headers, e.g. List-Unsubscribe
     */
    public function send(string $toEmail, ?string $toName, RenderedEmail $email, array $headers = []): MailSendResult;
}
