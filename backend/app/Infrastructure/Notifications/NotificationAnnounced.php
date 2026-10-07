<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One notification, pushed to the person it is for.
 *
 * **A private channel per person per workspace**, and nothing broader. A workspace-wide channel would be
 * simpler and would mean every member's browser receiving every notification and filtering client-side —
 * which is not filtering, it is delivery followed by politeness. `routes/channels.php` authorises the channel
 * against active membership.
 *
 * **The payload is a signal, not the notification.** It carries the id, the kind, whether it needs action and
 * when it arrived — and nothing a person would read. No title, no body, no subject. The browser treats the
 * event as "go and read the feed", which it does over the authenticated endpoint that already decides what a
 * reader may see.
 *
 * That is a deliberate trade of one extra request for a property worth having: **the contents of a notification
 * never travel over a socket.** A payload is the one place in a codebase where a field added without thinking
 * is delivered to a browser whose permissions nobody re-checked, and this shape makes that impossible rather
 * than unlikely. It also keeps the shaping of a notification for a reader in exactly one place — the HTTP
 * resource — instead of two that drift.
 */
final class NotificationAnnounced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array{id: string, kind: string, needs_action: bool, created_at: string} $signal */
    public function __construct(
        private readonly string $workspaceId,
        private readonly string $userId,
        public array $signal,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('workspace.%s.user.%s', $this->workspaceId, $this->userId))];
    }

    public function broadcastAs(): string
    {
        return 'notification.announced';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['signal' => $this->signal];
    }
}
