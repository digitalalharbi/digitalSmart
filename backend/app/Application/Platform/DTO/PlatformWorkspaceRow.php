<?php

declare(strict_types=1);

namespace App\Application\Platform\DTO;

use DateTimeImmutable;

/**
 * One tenant as the platform sees it: counts and states, never a client's name, a figure or a credential.
 *
 * `provisioning` is `complete` when the workspace has an active owner, its system roles and a subscription — the three things it
 * cannot function without — and names what is missing otherwise.
 */
final readonly class PlatformWorkspaceRow
{
    /**
     * @param  array<string, int>  $connections  connection status → count
     * @param  list<string>  $missing  what provisioning lacks
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $type,
        public DateTimeImmutable $createdAt,
        public string $planCode,
        public string $status,
        public ?DateTimeImmutable $trialEndsAt,
        public ?DateTimeImmutable $graceEndsAt,
        public ?string $statusReason,
        public int $owners,
        public int $internalSeats,
        public int $clientSeats,
        public int $clients,
        public int $projects,
        public array $connections,
        public int $overrides,
        public array $missing,
    ) {}
}
