<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Reporting\ShareState;
use DateTimeImmutable;

/** One shared link. */
final readonly class ShareView
{
    public function __construct(
        public string $id,
        public string $reportId,
        /** Set when the link shows a frozen snapshot; null when it recomputes on every open. */
        public ?string $snapshotId,
        /** Twelve characters, enough to recognise a link and not enough to use one. */
        public string $hint,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?string $revokedReason,
        public ?string $recipientLabel,
        public int $viewCount,
        public ?DateTimeImmutable $lastViewedAt,
        public ?string $createdByName,
        public DateTimeImmutable $createdAt,
        public string $workspaceId,
    ) {}

    public function state(DateTimeImmutable $now): ShareState
    {
        return ShareState::of($this->revokedAt !== null, $this->expiresAt, $now);
    }

    /** Whether this link shows figures frozen at a moment, rather than today's. */
    public function isFrozen(): bool
    {
        return $this->snapshotId !== null;
    }
}
