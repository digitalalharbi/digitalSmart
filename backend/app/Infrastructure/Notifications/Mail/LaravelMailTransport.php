<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Mail;

use App\Application\Notifications\Mail\MailSendResult;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Notifications\Mail\RenderedEmail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Throwable;

/**
 * The framework's mailer, behind three conditions that must all hold before a single message leaves.
 *
 * A mailer that actually transmits (`log` and `array` write to a file and to memory, and neither is sending); a sender
 * address on a real domain; and an explicit switch, `MAIL_DELIVERY_ENABLED`, that is off unless somebody turned it on.
 * The switch is separate on purpose: credentials present in an environment are not a decision to email clients from
 * it, and a staging server holding production's SMTP password must not start writing to real inboxes by being deployed.
 *
 * Provider and sender credentials live in the server's environment, never in the repository.
 */
final readonly class LaravelMailTransport implements MailTransport
{
    /** Mailers that never transmit. */
    private const array NON_SENDING = ['log', 'array', ''];

    /** @param list<string> $placeholderDomains sender domains that are examples, not identities */
    public function __construct(
        private MailFactory $mail,
        private string $mailer,
        private string $fromAddress,
        private string $fromName,
        private bool $enabled,
        private array $placeholderDomains = ['example.com', 'example.org', 'digitalsmart.local', 'localhost'],
    ) {}

    public function isConfigured(): bool
    {
        return $this->missing() === '';
    }

    public function missing(): string
    {
        $missing = [];

        if (in_array($this->mailer, self::NON_SENDING, true)) {
            $missing[] = 'a mail provider (MAIL_MAILER is "'.$this->mailer.'", which does not send)';
        }

        $domain = mb_strtolower((string) substr(strrchr($this->fromAddress, '@') ?: '', 1));

        if (filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false || in_array($domain, $this->placeholderDomains, true)) {
            $missing[] = 'a sender address on a domain the agency controls (MAIL_FROM_ADDRESS)';
        }

        if (! $this->enabled) {
            $missing[] = 'the delivery switch (MAIL_DELIVERY_ENABLED=true)';
        }

        return $missing === [] ? '' : 'Email is not sent from this installation yet. Missing: '.implode('; ', $missing).'.';
    }

    public function send(string $toEmail, ?string $toName, RenderedEmail $email, array $headers = []): MailSendResult
    {
        if (! $this->isConfigured()) {
            return new MailSendResult(false, null, $this->missing());
        }

        try {
            $sent = $this->mail->mailer($this->mailer)->html($email->html, function (Message $message) use ($toEmail, $toName, $email, $headers): void {
                $message->to($toEmail, $toName)->from($this->fromAddress, $this->fromName)->subject($email->subject)->text($email->text);

                foreach ($headers as $name => $value) {
                    $message->getHeaders()->addTextHeader($name, $value);
                }
            });

            return new MailSendResult(true, $sent instanceof SentMessage ? $sent->getMessageId() : null);
        } catch (Throwable $failure) {
            // The provider's own words, without the message body or the recipient's address.
            return new MailSendResult(false, null, mb_substr($failure->getMessage(), 0, 300));
        }
    }
}
