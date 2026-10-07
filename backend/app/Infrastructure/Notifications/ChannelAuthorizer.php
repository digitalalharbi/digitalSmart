<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Domain\Workspace\MembershipStatus;
use Illuminate\Support\Facades\DB;

/**
 * Who may listen on a notification channel.
 *
 * A class rather than a closure in `routes/channels.php`, because this **is** the access control on the realtime
 * layer and a rule that matters belongs somewhere a test can reach without a WebSocket server. A notification
 * delivered to the wrong browser would have bypassed every permission check in the module.
 *
 * Two conditions, and both are necessary:
 *
 *  - **The subscriber is who they claim.** Without this, a notification's contents — a client, a campaign, an
 *    intention — would be readable by anybody who could guess a user id.
 *  - **Their membership of that workspace is still active.** Re-checked on every subscription rather than taken
 *    from the session: somebody suspended between opening the page and the next notification must stop
 *    receiving them, and a socket authorised once would otherwise keep delivering for as long as the tab
 *    stayed open.
 */
final readonly class ChannelAuthorizer
{
    public function mayListen(string $actorId, string $workspaceId, string $userId): bool
    {
        if ($actorId !== $userId) {
            return false;
        }

        return DB::table('workspace_members')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
