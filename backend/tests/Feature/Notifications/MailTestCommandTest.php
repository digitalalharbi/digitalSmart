<?php

declare(strict_types=1);

use App\Application\Notifications\Mail\MailSendResult;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Notifications\Mail\RenderedEmail;

/*
| The acceptance command: one message, to the address typed, only through a configured provider and only when the
| operator says the inbox is theirs — and it never reports arrival.
*/

final class AcceptanceOutbox implements MailTransport
{
    /** @var list<string> */
    public array $to = [];

    public function __construct(public bool $configured = true, public bool $accepts = true) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function missing(): string
    {
        return $this->configured ? '' : 'Email is not sent from this installation yet. Missing: a mail provider.';
    }

    public function send(string $toEmail, ?string $toName, RenderedEmail $email, array $headers = []): MailSendResult
    {
        if (! $this->accepts) {
            return new MailSendResult(false, null, '535 authentication failed');
        }

        $this->to[] = $toEmail;

        return new MailSendResult(true, 'abc-123');
    }
}

function acceptanceOutbox(bool $configured = true, bool $accepts = true): AcceptanceOutbox
{
    $outbox = new AcceptanceOutbox($configured, $accepts);
    app()->instance(MailTransport::class, $outbox);

    return $outbox;
}

it('says what is missing and sends nothing when no provider is configured', function (): void {
    $outbox = acceptanceOutbox(configured: false);

    test()->artisan('mail:test', ['address' => 'me@agency.sa', '--i-own-this-inbox' => true])
        ->expectsOutputToContain('Missing: a mail provider')
        ->expectsOutputToContain('Nothing was sent.')
        ->assertFailed();

    expect($outbox->to)->toBe([]);
});

it('will not write to an address until the operator says the inbox is theirs', function (): void {
    $outbox = acceptanceOutbox();

    test()->artisan('mail:test', ['address' => 'me@agency.sa'])->expectsOutputToContain('--i-own-this-inbox')->assertExitCode(2);

    expect($outbox->to)->toBe([]);
});

it('sends exactly one message and says acceptance is not arrival', function (): void {
    $outbox = acceptanceOutbox();

    test()->artisan('mail:test', ['address' => 'Me@Agency.sa', '--i-own-this-inbox' => true, '--locale' => 'en'])
        ->expectsOutputToContain('Accepted by the mail provider (message id abc-123).')
        ->expectsOutputToContain('That is not arrival.')
        ->assertSuccessful();

    expect($outbox->to)->toBe(['me@agency.sa']);
});

it('reports the provider’s refusal in its own words', function (): void {
    acceptanceOutbox(accepts: false);

    test()->artisan('mail:test', ['address' => 'me@agency.sa', '--i-own-this-inbox' => true])
        ->expectsOutputToContain('535 authentication failed')
        ->assertFailed();
});

it('refuses something that is not an address', function (): void {
    $outbox = acceptanceOutbox();

    test()->artisan('mail:test', ['address' => 'not-an-address', '--i-own-this-inbox' => true])->assertExitCode(2);

    expect($outbox->to)->toBe([]);
});
