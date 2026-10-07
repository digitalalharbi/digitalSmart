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
