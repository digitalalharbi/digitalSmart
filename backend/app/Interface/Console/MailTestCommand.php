<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Notifications\Mail\EmailKind;
use App\Application\Notifications\Mail\EmailRenderer;
use App\Application\Notifications\Mail\MailTransport;
use Illuminate\Console\Command;

/**
 * Send one test message to an inbox the operator owns, through the same transport every real email uses.
 *
 * The acceptance step no test suite can perform: a message leaving a real server and being read in a real inbox. It
 * sends exactly one, only to the address typed, only when the operator states the inbox is theirs — and what it prints
 * is what is known. "Accepted" is the mail server taking the message; whether it arrived is for the person at the
 * inbox to say, so the command tells them to go and look rather than reporting success on their behalf.
 */
final class MailTestCommand extends Command
{
    protected $signature = 'mail:test {address : An inbox you control} {--locale=ar : ar or en} {--i-own-this-inbox : Required. This writes to a real address}';

    protected $description = 'Send one test email through the configured provider, to an inbox you own';

    public function handle(MailTransport $mail, EmailRenderer $renderer): int
    {
        $typed = $this->argument('address');
        $address = is_string($typed) ? mb_strtolower(trim($typed)) : '';

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('That is not an email address.');

            return self::INVALID;
        }

        if (! $mail->isConfigured()) {
            // Before anything else, and without sending: the commonest answer on a new server is what is missing.
            $this->components->error($mail->missing());
            $this->line('Nothing was sent.');

            return self::FAILURE;
        }

        if ($this->option('i-own-this-inbox') !== true) {
            $this->components->error('Pass --i-own-this-inbox to confirm the address is yours. Nothing was sent.');

            return self::INVALID;
        }

        $arabic = $this->option('locale') !== 'en';
        $email = $renderer->render(EmailKind::NeedsAttention, $arabic ? 'ar' : 'en', (string) config('app.frontend_url'), [
            'subject' => $arabic
                ? 'هذه رسالة اختبار للتأكد من أن البريد يخرج من هذا الخادم. لا يلزمك أي إجراء.'
                : 'This is a test that email leaves this server. There is nothing you need to do.',
        ]);

        $result = $mail->send($address, null, $email);

        if (! $result->accepted) {
            $this->components->error('The mail provider refused the message: '.($result->detail ?? 'no reason given'));

            return self::FAILURE;
        }

        $this->components->info('Accepted by the mail provider'.($result->messageId === null ? '.' : ' (message id '.$result->messageId.').'));
        $this->line('That is not arrival. Open the inbox, and check the spam folder: only a message you can read proves delivery.');

        return self::SUCCESS;
    }
}
