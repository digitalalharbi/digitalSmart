<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\DispatchContext;
use App\Domain\Tracking\Dispatch\SkipReason;

interface ProviderDispatchStore
{
    /**
     * Record that a conversion matched a mapping. Null when this mapping already holds a dispatch for the event id
     * (a repeat is one dispatch), otherwise the new dispatch id.
     *
     * @param  array{id: string, event: string, provider: string, provider_event: string, enabled: bool}  $mapping
     */
    public function open(DispatchContext $context, array $mapping, string $channel, ?SkipReason $skip): ?string;

    /** @return array{id: string, workspace_id: string, project_id: string, provider: string, provider_event: string, event: string, event_id: string, channel: string, status: string, attempts: int, occurred_at: string, click_ids: array<string, string>}|null */
    public function find(string $id): ?array;

    public function markSent(string $id, string $sourceState, ?string $providerRef, ?bool $providerReceived, ?bool $deduplicated): void;

    public function markFailed(string $id, string $category): void;

    public function markUnknown(string $id): void;

    public function markSkipped(string $id, SkipReason $reason): void;

    public function attempt(string $id): void;

    /** @return list<array<string, mixed>> */
    public function list(string $workspaceId, string $projectId, int $limit): array;

    /**
     * What a lead's tracked visit carried, for dispatching a conversion that happens later in its life.
     *
     * @return array{project_id: string, visit: ?array{consent: string, traffic_class: string, click_ids: array<string, string>}}|null null when the lead has no project
     */
    public function visitOfLead(string $workspaceId, string $leadId): ?array;

    public function resetForRetry(string $workspaceId, string $id): bool;
}
