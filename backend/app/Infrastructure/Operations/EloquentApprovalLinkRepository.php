<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\Contract\ApprovalLinkRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentApprovalLinkRepository implements ApprovalLinkRepository
{
    public function create(string $workspaceId, string $approvalId, string $userId, ?string $issuedBy, string $channel, string $tokenHash, string $fingerprint, DateTimeImmutable $expiresAt, string $delivery, DateTimeImmutable $now): string
    {
        $id = (string) Str::uuid();

        DB::table('approval_links')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'approval_id' => $approvalId,
            'user_id' => $userId,
            'issued_by' => $issuedBy,
            'channel' => $channel,
            'token_hash' => $tokenHash,
            'fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'delivery' => $delivery,
            'created_at' => $now,
        ]);

        return $id;
    }

    public function findByHash(string $tokenHash): ?array
    {
        $row = DB::table('approval_links')->where('token_hash', $tokenHash)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'workspace_id' => (string) $row->workspace_id,
            'approval_id' => (string) $row->approval_id,
            'user_id' => (string) $row->user_id,
            'channel' => (string) $row->channel,
            'fingerprint' => (string) $row->fingerprint,
            'expires_at' => new DateTimeImmutable((string) $row->expires_at),
            'used_at' => $row->used_at === null ? null : new DateTimeImmutable((string) $row->used_at),
        ];
    }

    public function claim(string $id, DateTimeImmutable $now): bool
    {
        return DB::table('approval_links')
            ->where('id', $id)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now]) === 1;
    }

    public function recordOutcome(string $id, string $outcome): void
    {
        DB::table('approval_links')->where('id', $id)->update(['outcome' => $outcome]);
    }
}
