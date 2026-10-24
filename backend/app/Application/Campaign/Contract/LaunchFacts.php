<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\AccountFacts;

/** The facts a preflight reads. Everything is read fresh; nothing here writes. */
interface LaunchFacts
{
    public function account(string $workspaceId, string $projectId, string $externalAccountId): ?AccountFacts;

    /** @return array{status: string, expires_at: string|null}|null */
    public function trackingLink(string $workspaceId, string $linkId): ?array;

    /**
     * @param  list<string>  $ids
     * @return list<array{id: string, title: string, state: string, provider: string|null, versions: int}>
     */
    public function creatives(string $workspaceId, array $ids): array;

    /** @return list<string> providers the project sends conversions to */
    public function conversionProviders(string $workspaceId, string $projectId): array;

    public function workspaceTimezone(string $workspaceId): string;
}
