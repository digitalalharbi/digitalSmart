<?php

declare(strict_types=1);

namespace App\Infrastructure\Reporting;

use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\DTO\ShareView;
use App\Application\Shared\Contract\IdentifierGenerator;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared report links.
 *
 * **The token is generated here and hashed before it touches the database.** Only the hash and a twelve-character
 * hint are stored. A share link is a credential — pasted into e-mail, forwarded, occasionally landing in a thread
 * nobody intended — so a database dump must not hand out working links to a client's performance data.
 *
 * The raw token is returned exactly once, from `create()`. Nothing reads it back, which is deliberate: a link
 * somebody lost is a link they should replace rather than recover.
 *
 * The lookup is **by hash**, so the stored value is never compared against anything but another hash, and it finds
 * revoked and expired links rather than hiding them — the caller refuses them with a sentence, because a 404 on a
 * link a client was sent reads as a broken site and they ask for it again.
 */
final readonly class EloquentReportShareStore implements ReportShareStore
{
    /**
     * Forty-eight bytes of randomness, hex-encoded.
     *
     * Longer than a session id on purpose: this token is the only thing between a URL and a client's spend, it
     * travels through e-mail, and the person holding it cannot rotate it.
     */
    private const int TOKEN_BYTES = 48;

    public function __construct(private IdentifierGenerator $identifiers) {}

    public function create(
        string $workspaceId,
        string $reportId,
        ?string $snapshotId,
        DateTimeImmutable $expiresAt,
        ?string $recipientLabel,
        ?string $actorId,
    ): array {
        $id = $this->identifiers->generate();
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        DB::table('report_shares')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'report_id' => $reportId,
            'snapshot_id' => $snapshotId,
            // The hash, never the token.
            'token_hash' => hash('sha256', $token),
            'token_hint' => Str::substr($token, 0, 12),
            'expires_at' => $expiresAt,
            'recipient_label' => $recipientLabel,
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $share = $this->find($workspaceId, $id) ?? throw new Exception('The share was not written.');

        return ['share' => $share, 'token' => $token];
    }

    public function findByToken(string $token): ?ShareView
    {
        if (trim($token) === '') {
            return null;
        }

        $row = DB::table('report_shares as s')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.token_hash', hash('sha256', $token))
            ->first(['s.*', 'u.name as created_by_name']);

        return $row === null ? null : $this->view((array) $row);
    }

    public function forReport(string $workspaceId, string $reportId): array
    {
        $rows = DB::table('report_shares as s')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.workspace_id', $workspaceId)
            ->where('s.report_id', $reportId)
            ->orderByDesc('s.created_at')
            ->get(['s.*', 'u.name as created_by_name']);

        $shares = [];

        foreach ($rows as $row) {
            $shares[] = $this->view((array) $row);
        }

        return $shares;
    }

    public function revoke(
        string $workspaceId,
        string $shareId,
        string $reason,
        DateTimeImmutable $at,
    ): ?ShareView {
        $changed = DB::table('report_shares')
            ->where('workspace_id', $workspaceId)
            ->where('id', $shareId)
            /*
             * Only an unrevoked link. Revoking twice would move the moment it stopped working, which is the one
             * fact somebody needs when they are working out what a recipient could have seen and when.
             */
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $at, 'revoked_reason' => $reason, 'updated_at' => now()]);

        if ($changed === 0) {
            return null;
        }

        return $this->find($workspaceId, $shareId);
    }

    public function recordView(string $shareId, DateTimeImmutable $at): void
    {
        /*
         * An increment, not an insert. An agency wants to know whether a client read the report, not to build a
         * behavioural profile of them — and a per-view table of a client's reading habits is data this product has
         * no reason to hold.
         */
        DB::table('report_shares')
            ->where('id', $shareId)
            ->update([
                'view_count' => DB::raw('view_count + 1'),
                'last_viewed_at' => $at,
                'updated_at' => now(),
            ]);
    }

    private function find(string $workspaceId, string $shareId): ?ShareView
    {
        $row = DB::table('report_shares as s')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.workspace_id', $workspaceId)
            ->where('s.id', $shareId)
            ->first(['s.*', 'u.name as created_by_name']);

        return $row === null ? null : $this->view((array) $row);
    }

    /** @param array<string, mixed> $values */
    private function view(array $values): ShareView
    {
        return new ShareView(
            id: (string) ($values['id'] ?? ''),
            reportId: (string) ($values['report_id'] ?? ''),
            snapshotId: $this->text($values['snapshot_id'] ?? null),
            hint: (string) ($values['token_hint'] ?? ''),
            expiresAt: $this->date($values['expires_at'] ?? null) ?? new DateTimeImmutable('@0'),
            revokedAt: $this->date($values['revoked_at'] ?? null),
            revokedReason: $this->text($values['revoked_reason'] ?? null),
            recipientLabel: $this->text($values['recipient_label'] ?? null),
            viewCount: (int) ($values['view_count'] ?? 0),
            lastViewedAt: $this->date($values['last_viewed_at'] ?? null),
            createdByName: $this->text($values['created_by_name'] ?? null),
            createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
            workspaceId: (string) ($values['workspace_id'] ?? ''),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
