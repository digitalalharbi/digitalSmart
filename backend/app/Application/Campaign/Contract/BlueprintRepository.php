<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\BlueprintInput;
use App\Application\Campaign\DTO\BlueprintView;
use App\Domain\Advertising\Blueprint\BlueprintStatus;

interface BlueprintRepository
{
    /** The project's client, or null when the project is not in this workspace. */
    public function clientOf(string $workspaceId, string $projectId): ?string;

    /**
     * The given ids that are ad accounts bound to the project, with their provider. Anything else is not returned.
     *
     * @param  list<string>  $ids
     * @return array<string, string> external account id → provider
     */
    public function boundAccounts(string $workspaceId, string $projectId, array $ids): array;

    public function trackingLinkInProject(string $workspaceId, string $projectId, string $linkId): bool;

    public function conversionForClient(string $workspaceId, string $clientId, string $eventId): bool;

    /**
     * @param  list<string>  $ids
     * @return list<string> the ids that are this client's Creative Hub requests
     */
    public function creativesForClient(string $workspaceId, string $clientId, array $ids): array;

    /** @param array<string, string> $targets external account id → provider */
    public function create(string $id, string $workspaceId, string $clientId, BlueprintInput $input, array $targets, string $createdBy): void;

    /**
     * Replace the plan and bump its version; a plan under review goes back to draft.
     *
     * @param  array<string, string>  $targets
     */
    public function update(string $workspaceId, string $id, BlueprintInput $input, array $targets): void;

    public function setStatus(string $workspaceId, string $id, BlueprintStatus $status, ?string $reason, ?string $byUserId): void;

    /** @param list<string>|null $visibleProjectIds null: every project */
    public function find(string $workspaceId, string $id, ?array $visibleProjectIds): ?BlueprintView;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array{rows: list<BlueprintView>, total: int}
     */
    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $status, ?string $projectId, int $page, int $perPage): array;

    /**
     * What a plan for this project can be made of: its bound ad accounts, its tracking links, the client's conversions and the
     * client's open Creative Hub requests.
     *
     * @return array{accounts: list<array<string, mixed>>, tracking_links: list<array<string, mixed>>, conversions: list<array<string, mixed>>, creatives: list<array<string, mixed>>}
     */
    public function options(string $workspaceId, string $projectId): array;
}
