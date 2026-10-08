<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Domain\Operations\OperationsBoard;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may listen on a notification channel.
 *
 * A class rather than a closure in `routes/channels.php`, because this **is** the access control on the realtime
 * layer and a rule that matters belongs somewhere a test can reach without a WebSocket server. A notification
 * delivered to the wrong browser would have bypassed every permission check in the module.
 *
 * Two kinds of channel, with different rules, because they answer different questions.
 *
 * A **notification** channel is one person's, and the rule is identity plus active membership. A **board**
 * channel is a workspace's, and the rule is the permission that board requires plus active membership — the
 * payload carries no rows, so the question is "may this person read this board at all" rather than "which rows".
 *
 * Two conditions on the notification channel, and both are necessary:
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
    /**
     * Whether somebody may watch a board's channel.
     *
     * The permission is checked **through their role in that workspace**, not through the session's resolved
     * permissions: a member of two workspaces has two roles, and the session's answer is about whichever one they
     * are currently in. Re-checked on every subscription for the same reason membership is — a role changed
     * between opening the page and the next move must take effect, and a socket authorised once would otherwise
     * keep nudging for as long as the tab stayed open.
     */
    public function mayWatchBoard(string $actorId, string $workspaceId, string $board): bool
    {
        $subject = OperationsBoard::tryFrom($board);

        if ($subject === null) {
            return false;
        }

        return DB::table('workspace_members as m')
            ->where('m.workspace_id', $workspaceId)
            ->where('m.user_id', $actorId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereExists(function (Builder $query) use ($subject): void {
                $query->select(DB::raw('1'))
                    ->from('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->whereColumn('pr.role_id', 'm.role_id')
                    ->where('p.slug', $subject->requires()->value);
            })
            ->exists();
    }

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
