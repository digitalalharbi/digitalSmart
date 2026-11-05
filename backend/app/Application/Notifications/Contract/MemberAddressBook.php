<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

/**
 * Where a member's own copy of a notification may be emailed.
 *
 * Asked at the moment of sending and answered only for somebody who is an active member of that workspace right now:
 * a notification written an hour ago for somebody removed since is not emailed to them.
 */
interface MemberAddressBook
{
    /** @return array{email: string, name: string, locale: 'ar'|'en'}|null */
    public function addressOf(string $workspaceId, string $userId): ?array;
}
