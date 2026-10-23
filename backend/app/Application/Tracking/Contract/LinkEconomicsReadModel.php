<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Domain\Tracking\Cost\LinkSpend;
use DateTimeImmutable;

/**
 * What a tracking link cost and what it earned, read from the entities the link is bound to.
 *
 * Nothing is stored on the link: spend comes from the campaign hierarchy's own daily metrics and revenue from the leads and
 * orders the link's traffic produced, both over the period asked for.
 */
interface LinkEconomicsReadModel
{
    /** The link's spend and how firmly it is the link's; `$allocateByClicks` is the only allocation offered and is never default. */
    public function spend(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to, bool $allocateByClicks): LinkSpend;

    /**
     * Revenue by source, never merged. Each entry is a figure or null with the reason there is none.
     *
     * @return array<string, array{minor: ?int, currency: ?string, count: ?int, reason: ?string, evidence: string}>
     */
    public function revenue(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
