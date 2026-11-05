<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\MemberAddressBook;
use App\Domain\Workspace\MembershipStatus;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentMemberAddressBook implements MemberAddressBook
{
    public function addressOf(string $workspaceId, string $userId): ?array
    {
        $row = DB::table('users as u')
            ->join('workspace_members as m', 'm.user_id', '=', 'u.id')
            ->where('u.id', $userId)
            ->where('m.workspace_id', $workspaceId)
            ->where('m.status', MembershipStatus::Active->value)
            ->whereNull('m.revoked_at')
            ->first(['u.email', 'u.name', 'u.locale']);

        if (! $row instanceof stdClass || ! is_string($row->email) || $row->email === '') {
            return null;
        }

        return ['email' => $row->email, 'name' => (string) $row->name, 'locale' => $row->locale === 'ar' ? 'ar' : 'en'];
    }
}
