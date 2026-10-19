<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Contract;

/**
 * A workspace's own model key ("bring your own key"), encrypted at rest and never read back out to a screen.
 */
interface ModelCredentialStore
{
    /** @return array{api_key: string, strong_model: string|null, fast_model: string|null}|null */
    public function credentials(string $workspaceId): ?array;

    /** @return array{configured: bool, last4: string|null, strong_model: string|null, fast_model: string|null, updated_at: string|null} */
    public function describe(string $workspaceId): array;

    public function save(string $workspaceId, ?string $apiKey, ?string $strongModel, ?string $fastModel, string $userId): void;

    public function forget(string $workspaceId): void;
}
