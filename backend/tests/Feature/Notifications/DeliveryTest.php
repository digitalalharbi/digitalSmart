<?php

declare(strict_types=1);

use App\Application\Notifications\DTO\NotificationView;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use App\Infrastructure\Notifications\HonestChannelDelivery;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyncedWorkspace;

/*
|--------------------------------------------------------------------------
| Delivery, in the state it is in
|--------------------------------------------------------------------------
|
| Nothing is sent. What is asserted is that the product says so: a person who asked for email and WhatsApp gets a
| delivery row per channel with an honest status, never `sent`; the bell itself is never a delivery row; the row
| names what is missing; and the history reaches the feed as the owner's own.
|
*/

function deliveryView(): NotificationView
{
    return new NotificationView(
        id: (string) Illuminate\Support\Str::uuid7(),
        recipientId: (string) Illuminate\Support\Str::uuid7(),
        kind: NotificationKind::PerformanceDigest,
        title: 'Daily performance digest',
        body: null,
        subject: NotificationSubject::Digest,
        subjectId: (string) Illuminate\Support\Str::uuid7(),
        subjectLabel: null,
        subjectExists: true,
        actorName: null,
        readAt: null,
        createdAt: new DateTimeImmutable,
    );
}

it('answers awaiting credentials outside development, naming what each channel is missing', function (): void {
    $delivery = new HonestChannelDelivery(logInDevelopment: false);

    $email = $delivery->deliver(DigestChannel::Email, 'ws', deliveryView());
    $whatsapp = $delivery->deliver(DigestChannel::WhatsApp, 'ws', deliveryView());

    expect($email->status->value)->toBe('awaiting_credentials')
        ->and($email->detail)->toContain('MAIL_MAILER')
        ->and($whatsapp->status->value)->toBe('awaiting_credentials')
        ->and($whatsapp->detail)->toContain('WhatsApp Business API');
});

it('writes one honest row per chosen channel for a digest, none for the bell, and never says sent', function (): void {
    $w = SyncedWorkspace::default();
    $owner = $w->scenario->owner;
    $zone = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('G') >= 9 ? 'UTC' : 'Pacific/Auckland';

    test()->actingAs($owner)->putJson('/api/notifications/preferences', [
        'daily' => true, 'weekly' => true, 'monthly' => true, 'hour' => 0, 'timezone' => $zone,
        'channels' => ['in_app', 'email', 'whatsapp'], 'muted_categories' => [],
    ])->assertOk();

    $this->artisan('digests:performance daily_performance')->assertSuccessful();

    $notification = (string) DB::table('notifications')->where('kind', 'performance_digest')->where('user_id', $owner->getKey())->value('id');
    $rows = DB::table('notification_deliveries')->where('notification_id', $notification)->orderBy('channel')->get(['channel', 'status']);

    expect($rows->pluck('channel')->all())->toBe(['email', 'whatsapp'])
        ->and($rows->pluck('status')->unique()->all())->not->toContain('sent')
        ->and(DB::table('notification_deliveries')->where('channel', 'in_app')->count())->toBe(0);

    $feed = test()->actingAs($owner)->getJson('/api/notifications?category=performance')->assertOk()->json('data.notifications');
    $mine = array_values(array_filter($feed, static fn (array $n): bool => $n['id'] === $notification))[0] ?? null;

    expect($mine)->not->toBeNull()
        ->and(array_column($mine['deliveries'], 'channel'))->toBe(['email', 'whatsapp'])
        ->and($mine['deliveries'][0])->toHaveKeys(['status', 'detail', 'attempted_at']);
});

it('writes no delivery row for somebody who wants the bell alone', function (): void {
    $w = SyncedWorkspace::default();

    $this->artisan('digests:performance daily_performance')->assertSuccessful();

    expect(DB::table('notifications')->where('kind', 'performance_digest')->count())->toBe(1)
        ->and(DB::table('notification_deliveries')->count())->toBe(0)
        ->and($w->workspaceId())->not->toBe('');
});
