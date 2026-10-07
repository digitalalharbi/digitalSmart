<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\NotificationBroadcaster;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Pushing a notification over Reverb, when there is a Reverb to push over.
 *
 * **Realtime is optional, and this class is where that is honest.** With no broadcast connection configured —
 * which is the state of every checkout until somebody runs a WebSocket server — `isConfigured()` is false,
 * `push()` does nothing, and the interface says live updates are off. The notification is already in the
 * database, so it arrives on the next read: later, never missing.
 *
 * The alternative shapes are both worse. Throwing would fail the action that caused the notification. Silently
 * attempting a push would fill the log with connection errors on every developer's machine and teach everybody
 * to ignore the log.
 *
 * A failed push is reported and swallowed for the same reason the audit recorder swallows: the notification is
 * stored, and failing the approval that produced it because a socket was unavailable would be the wrong trade
 * by a wide margin.
 */
final readonly class ReverbNotificationBroadcaster implements NotificationBroadcaster
{
    public function __construct(
        private Dispatcher $events,
        private Config $config,
    ) {}

    public function push(string $workspaceId, array $notifications): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        foreach ($notifications as $notification) {
            try {
                $this->events->dispatch(new NotificationAnnounced(
                    $workspaceId,
                    // The channel is per person, which is the only reason the view carries a recipient at all.
                    $notification->recipientId,
                    /*
                     * A signal, not the notification. See the note on the event: the payload says that
                     * something arrived and what sort, and the browser then reads the feed over the
                     * authenticated endpoint. The title, the body and the subject never travel over the socket.
                     */
                    [
                        'id' => $notification->id,
                        'kind' => $notification->kind->value,
                        'needs_action' => $notification->kind->needsAction(),
                        'created_at' => $notification->createdAt->format(DateTimeImmutable::ATOM),
                    ],
                ));
            } catch (Throwable $failure) {
                report($failure);
            }
        }
    }

    /**
     * Whether a push could actually reach anybody.
     *
     * The connection name is not enough, and assuming it was produced the exact failure this method exists to
     * prevent: a checkout with `BROADCAST_CONNECTION=reverb` and no app key told every reader the page was live
     * while nothing could connect, which is worse than saying it is off. So the key is checked too.
     *
     * A connection with no key is a configuration half-finished, not a feature. Saying so is the honest answer,
     * and the notification is in the database either way.
     */
    public function isConfigured(): bool
    {
        $connection = $this->config->get('broadcasting.default');

        if (! is_string($connection) || $connection === 'null' || $connection === 'log') {
            return false;
        }

        $key = $this->config->get(sprintf('broadcasting.connections.%s.key', $connection));

        return is_string($key) && trim($key) !== '';
    }
}
