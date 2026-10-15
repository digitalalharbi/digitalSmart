<?php

declare(strict_types=1);

namespace App\Domain\Integration\Sync;

use App\Domain\Integration\Provider;
use DateTimeImmutable;

/** An account that could be synced, with the facts the planner decides on. Nothing here is secret. */
final readonly class SyncCandidate
{
    public function __construct(
        public string $workspaceId,
        public string $externalAccountId,
        public Provider $provider,
        /**
         * Who this work is *for*, so one large client cannot starve the rest. The owning client for a
         * client-granted connection, otherwise the client of a bound project.
         */
        public string $fairnessKey,
        public ?DateTimeImmutable $lastSuccessAt,
        public int $activeCampaigns,
        /** Whether any bound project is still being worked. False means history only. */
        public bool $hasLiveProject,
    ) {}
}
