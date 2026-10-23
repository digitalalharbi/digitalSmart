<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

interface ConversionMappingRepository
{
    /** @return list<array{id: string, event: string, provider: string, provider_event: string, enabled: bool}> */
    public function list(string $workspaceId, string $projectId): array;

    /** @return array{id: string, event: string, provider: string, provider_event: string, enabled: bool} */
    public function save(string $workspaceId, string $projectId, string $event, string $provider, string $providerEvent, bool $enabled, string $actorId): array;

    public function delete(string $workspaceId, string $projectId, string $id): bool;

    /** @return list<array{id: string, event: string, provider: string, provider_event: string, enabled: bool}> */
    public function enabledFor(string $projectId, string $event): array;
}
