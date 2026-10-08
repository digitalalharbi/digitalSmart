<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Domain\Operations\OperationsBoard;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One board changed. Nothing about how.
 *
 * **The payload is a nudge, not a row**, for the same reason a notification's is a signal rather than its
 * contents: a payload is the one place in a codebase where a field added without thinking is delivered to a
 * browser whose permissions nobody re-checked. This one carries the board's name and a timestamp, and the
 * browser's answer is to read the board over the authenticated endpoint — where project visibility already
 * decides which rows a person gets.
 *
 * That is what makes a **workspace-wide** channel safe here, where notifications need one per person. Everybody
 * who may read a board wants to know it moved, the permission on the channel decides who those people are, and
 * the event itself discloses only that something happened in a workspace the subscriber is already a member of.
 */
final class BoardChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly string $workspaceId,
        private readonly OperationsBoard $board,
        private readonly string $at,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(sprintf('workspace.%s.board.%s', $this->workspaceId, $this->board->value))];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['board' => $this->board->value, 'at' => $this->at];
    }
}
