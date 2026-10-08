<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Access\Permission;

/**
 * A board that can be open on somebody's screen while somebody else changes it.
 *
 * Three, and each names the permission a listener must hold. That permission is the whole of the access control
 * on this channel: a client viewer holds none of `tasks.view`, `approvals.view` or `client_requests.view`, so a
 * client cannot subscribe to the agency's boards at all — and the one board they *can* see, their own requests,
 * is reached through the permission that let them raise one.
 *
 * The signal carries no content, which is what makes a workspace-wide channel safe here. It says "this board
 * changed"; the browser then reads the board over the authenticated endpoint, where project visibility already
 * decides which rows a person gets. A payload carrying rows would have to answer that question a second time,
 * and the second answer is the one that eventually differs.
 */
enum OperationsBoard: string
{
    case Tasks = 'tasks';

    case Approvals = 'approvals';

    case ClientRequests = 'client_requests';

    public function requires(): Permission
    {
        return match ($this) {
            self::Tasks => Permission::TasksView,
            self::Approvals => Permission::ApprovalsView,
            /*
             * `submit` rather than `view`, so a client's own queue updates on their screen too. It is the
             * permission that let them raise a request, and the board they get back is already narrowed to their
             * own — the channel grants no reading that the endpoint would not.
             */
            self::ClientRequests => Permission::ClientRequestsSubmit,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
