<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\NotificationDraft;

/**
 * Who may be told.
 *
 * Its own port because it is the **security-critical** part of this module. A notification is a disclosure:
 * "Raise the Ramadan daily budget is waiting for your decision" names a client, a campaign and an intention. A
 * resolver that returned every workspace member would leak all three to people who cannot open the record it
 * refers to.
 *
 * So the rule is one sentence, and it is the same rule every read in this product follows: **a person may be
 * told about something only if they could have read it.** Permission, plus project visibility, plus active
 * membership.
 */
interface RecipientResolver
{
    /**
     * @return list<string> user ids, with the actor already excluded by the caller
     */
    public function resolve(string $workspaceId, NotificationDraft $draft): array;
}
