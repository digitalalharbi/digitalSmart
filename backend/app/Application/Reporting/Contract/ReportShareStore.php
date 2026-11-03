<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

use App\Application\Reporting\DTO\ShareView;
use DateTimeImmutable;

/**
 * Links that let somebody outside the workspace read one report.
 *
 * **The token is never stored.** Only a SHA-256 of it, and twelve characters of hint so a link is recognisable in
 * a list without being usable from it. A share link is a credential: it is pasted into e-mail, forwarded, and
 * sometimes ends up in a thread with people nobody intended. A database dump must not hand out working links to
 * clients' performance data.
 *
 * The raw token exists exactly once, in the response to the call that created it. There is no endpoint that
 * returns it again, which is deliberate: a link somebody lost is a link they should replace.
 */
interface ReportShareStore
{
    /**
     * Create a share and return the raw token alongside the record.
     *
     * @return array{share: ShareView, token: string}
     */
    public function create(
        string $workspaceId,
        string $reportId,
        ?string $snapshotId,
        DateTimeImmutable $expiresAt,
        ?string $recipientLabel,
        ?string $actorId,
    ): array;

    /**
     * Find a share by the token a visitor presented.
     *
     * Looked up by hash, so the stored value is never compared against anything but another hash. Returns the
     * record whatever its state — revoked and expired links are found and then refused with a sentence, because a
     * 404 on a link a client was sent reads as a broken site and they ask for it again.
     */
    public function findByToken(string $token): ?ShareView;

    /** @return list<ShareView> */
    public function forReport(string $workspaceId, string $reportId): array;

    /**
     * Switch a live link off without ending it. Null when it is not there, is another workspace's, or is not live
     * (revoked, expired or already paused) — one answer for all of them, so nothing is confirmed across a tenant.
     */
    public function pause(string $workspaceId, string $shareId, DateTimeImmutable $at): ?ShareView;

    /** Switch a paused link back on at the same address. Never extends the expiry and never revives a revoked link. */
    public function resume(string $workspaceId, string $shareId, DateTimeImmutable $at): ?ShareView;

    public function revoke(string $workspaceId, string $shareId, string $reason, DateTimeImmutable $at): ?ShareView;

    /**
     * Record that a link was opened.
     *
     * Counted rather than logged per view: an agency wants to know whether a client read the report, not to build
     * a behavioural profile of them. The count and the last time are the whole of it.
     */
    public function recordView(string $shareId, DateTimeImmutable $at): void;
}
