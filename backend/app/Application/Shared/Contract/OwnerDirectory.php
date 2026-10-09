<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Access\Permission;

/**
 * Who a piece of work can be put in the name of.
 *
 * **Not every member of the workspace.** Three conditions, and they are the same three
 * `PermissionAwareRecipientResolver` explains at length for notifications — active membership, the permission to
 * read the thing, and visibility of the client it belongs to. The rule is written there; this exists because a
 * picker needs names as well as ids, and because the answer has to be the same answer.
 *
 * The consequence of getting it wrong is specific rather than theoretical. A lead carries a prospect's phone
 * number; a client request carries a client's own words. Assigning one to somebody whose projects do not reach
 * that client would put their name in the owner column of a record they can never open — **and the notification
 * telling them would be correctly suppressed by the same visibility rule, so nobody would ever find out.** The
 * column would name a person who does not know.
 *
 * One directory rather than one per module, taking the permission as a parameter. Three boards needed this —
 * tasks, client requests and leads — and three copies of a visibility rule is a visibility rule that disagrees
 * with itself in one place nobody has looked at yet.
 *
 * Returns ids and names only. A picker does not need email addresses, and the people in it did not consent to
 * their addresses being listed to whoever is working a queue.
 */
interface OwnerDirectory
{
    /**
     * @param  string|null  $clientId  the client the work belongs to, or null where it belongs to none — a task
     *                                 raised about nothing in particular is reachable by anybody holding the
     *                                 permission, which is the same rule the notification resolver applies
     * @return list<OwnerCandidate>
     */
    public function peopleWhoCanSee(string $workspaceId, ?string $clientId, Permission $required): array;
}
