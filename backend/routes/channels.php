<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\ChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| One private channel per person per workspace, and nothing broader.
|
| A workspace-wide channel would be shorter and would mean every member's browser receiving every notification
| and filtering it client-side — which is not filtering, it is delivery followed by politeness.
|
| The rule itself lives in `ChannelAuthorizer` rather than in this closure, because it is the access control on
| the realtime layer and it has to be testable without a WebSocket server.
|
*/

Broadcast::channel(
    'workspace.{workspace}.user.{user}',
    fn (mixed $actor, string $workspace, string $user): bool => app(ChannelAuthorizer::class)
        ->mayListen((string) $actor->getKey(), $workspace, $user),
);

/*
 * One channel per board per workspace, authorised by the permission that board requires.
 *
 * Workspace-wide rather than per person, and safe to be: the payload is a nudge with no rows in it, so the
 * question this rule answers is "may this person read this board at all" — which the permission settles — rather
 * than "which rows may they see", which the authenticated endpoint settles on every refetch.
 */
Broadcast::channel(
    'workspace.{workspace}.board.{board}',
    fn (mixed $actor, string $workspace, string $board): bool => app(ChannelAuthorizer::class)
        ->mayWatchBoard((string) $actor->getKey(), $workspace, $board),
);
