<?php

declare(strict_types=1);

use App\Application\Notifications\Contract\NotificationChannelDelivery;
use App\Application\Notifications\DTO\NotificationView;
use App\Application\Notifications\Mail\MailSendResult;
use App\Application\Notifications\Mail\MailTransport;
use App\Application\Notifications\Mail\RenderedEmail;
use App\Domain\Access\SystemRole;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use Illuminate\Support\Str;
use Tests\Support\WorkspaceScenario;

/*
| A member's own email copy of a notification. It leaves through the one mail transport, to their own account address,
| while they are an active member — and without a configured provider it behaves exactly as it always did.
*/

/** A mail provider that keeps what it is handed. */
final class StaffMailOutbox implements MailTransport
{
    /** @var list<array{to: string, email: RenderedEmail}> */
    public array $sent = [];

    public function __construct(public bool $configured = true, public bool $accepts = true) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function missing(): string
    {
        return $this->configured ? '' : 'Email is not sent from this installation yet.';
    }

    public function send(string $toEmail, ?string $toName, RenderedEmail $email, array $headers = []): MailSendResult
    {
        if (! $this->accepts) {
            return new MailSendResult(false, null, '421 try again later');
        }

        $this->sent[] = ['to' => $toEmail, 'email' => $email];

        return new MailSendResult(true, 'staff-1');
    }
}

function staffOutbox(bool $configured = true, bool $accepts = true): StaffMailOutbox
{
    $outbox = new StaffMailOutbox($configured, $accepts);
    app()->instance(MailTransport::class, $outbox);

    return $outbox;
}

function staffNotification(string $recipientId): NotificationView
{
    return new NotificationView(
        id: (string) Str::uuid7(),
        recipientId: $recipientId,
        kind: NotificationKind::PerformanceDigest,
        title: 'Daily performance digest',
        body: 'Spend and results for yesterday are ready.',
        subject: NotificationSubject::Digest,
        subjectId: (string) Str::uuid7(),
        subjectLabel: null,
        subjectExists: true,
        actorName: null,
        readAt: null,
        createdAt: new DateTimeImmutable,
    );
}

it('emails the member’s own address and records the provider’s acceptance, not arrival', function (): void {
    $scenario = WorkspaceScenario::create();
    $outbox = staffOutbox();

    $outcome = app(NotificationChannelDelivery::class)
        ->deliver(DigestChannel::Email, (string) $scenario->workspace->getKey(), staffNotification((string) $scenario->owner->getKey()));

    expect($outcome->status)->toBe(DeliveryStatus::Sent)
        ->and($outcome->detail)->toContain('not confirmed')
        ->and($outbox->sent)->toHaveCount(1)
        ->and($outbox->sent[0]['to'])->toBe($scenario->owner->email)
        ->and($outbox->sent[0]['email']->text)->toContain('Daily performance digest');
});

it('sends nothing and keeps the old honest answer when no provider is configured', function (): void {
    $scenario = WorkspaceScenario::create();
    $outbox = staffOutbox(configured: false);

    $outcome = app(NotificationChannelDelivery::class)
        ->deliver(DigestChannel::Email, (string) $scenario->workspace->getKey(), staffNotification((string) $scenario->owner->getKey()));

    expect($outbox->sent)->toBe([])
        ->and($outcome->status)->not->toBe(DeliveryStatus::Sent);
});

it('records a refusal as failed, with the provider’s words', function (): void {
    $scenario = WorkspaceScenario::create();
    staffOutbox(accepts: false);

    $outcome = app(NotificationChannelDelivery::class)
        ->deliver(DigestChannel::Email, (string) $scenario->workspace->getKey(), staffNotification((string) $scenario->owner->getKey()));

    expect($outcome->status)->toBe(DeliveryStatus::Failed)->and($outcome->detail)->toContain('421');
});

it('does not email somebody who is no longer an active member, or a member of another workspace', function (): void {
    $scenario = WorkspaceScenario::create();
    $other = WorkspaceScenario::create();
    $outbox = staffOutbox();
    $member = $scenario->addMember(SystemRole::MediaBuyer);
    $delivery = app(NotificationChannelDelivery::class);

    test()->actingAs($scenario->owner)->deleteJson('/api/members/'.$member->getKey())->assertSuccessful();

    $removed = $delivery->deliver(DigestChannel::Email, (string) $scenario->workspace->getKey(), staffNotification((string) $member->getKey()));
    $stranger = $delivery->deliver(DigestChannel::Email, (string) $scenario->workspace->getKey(), staffNotification((string) $other->owner->getKey()));

    expect($outbox->sent)->toBe([])
        ->and($removed->status)->toBe(DeliveryStatus::Failed)
        ->and($stranger->status)->toBe(DeliveryStatus::Failed);
});

it('never sends a WhatsApp message through the mail transport', function (): void {
    $scenario = WorkspaceScenario::create();
    $outbox = staffOutbox();

    $outcome = app(NotificationChannelDelivery::class)
        ->deliver(DigestChannel::WhatsApp, (string) $scenario->workspace->getKey(), staffNotification((string) $scenario->owner->getKey()));

    expect($outbox->sent)->toBe([])->and($outcome->status)->not->toBe(DeliveryStatus::Sent);
});
