<?php

declare(strict_types=1);

namespace App\Application\Leads\Contract;

use App\Application\Leads\DTO\LeadOwnerCandidate;

/**
 * Who a lead can be put in the name of.
 *
 * **Not every member of the workspace.** Three conditions, and they are the same three
 * `PermissionAwareRecipientResolver` explains at length for notifications — active membership, the permission
 * to read the thing, and visibility of the client it belongs to. The rule is written there; this exists because
 * a picker needs names as well as ids, and because the answer has to be the same answer.
 *
 * The consequence of getting it wrong is specific rather than theoretical. A lead carries a prospect's name and
 * phone number. Assigning one to somebody whose projects do not reach that client would put their name in the
 * owner column of a record they can never open — and the notification telling them would be correctly
 * suppressed, so nobody would ever find out. The owner column would name a person who does not know.
 *
 * Returns ids and names only. A picker does not need email addresses, and the people in it did not consent to
 * their addresses being listed to whoever is working a pipeline.
 */
interface LeadOwnerDirectory
{
    /** @return list<LeadOwnerCandidate> */
    public function peopleWhoCanSee(string $workspaceId, string $clientId): array;
}
