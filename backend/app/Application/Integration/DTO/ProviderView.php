<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

/**
 * A provider as the interface sees it: what it is, what its API can do, and where this
 * workspace stands with it.
 *
 * The registry fields and the connection fields travel together on purpose. They could be
 * two endpoints, and then every page that needed a provider's *name* would have to join
 * them — which is how a second list of platform labels gets written. One response, one
 * source of truth.
 *
 * @param  list<array{slug: string, label: string, is_write: bool}>  $capabilities
 * @param  list<array{slug: string, label: string}>  $entities
 * @param  list<string>  $missingCredentials
 */
final readonly class ProviderView
{
    /**
     * @param  list<array{slug: string, label: string, is_write: bool}>  $capabilities
     * @param  list<array{slug: string, label: string}>  $entities
     * @param  list<string>  $missingCredentials
     * @param  array<string, mixed>|null  $rateLimit
     */
    public function __construct(
        public string $provider,
        public string $label,
        public string $category,
        public string $categoryLabel,
        public string $apiStatus,
        public string $apiStatusLabel,
        public string $authMethod,
        public array $capabilities,
        public array $entities,
        public ?array $rateLimit,
        public ?string $documentationUrl,
        public ?string $connectionId,
        public string $status,
        public string $statusLabel,
        public bool $canSync,
        public bool $needsAttention,
        public ?string $statusMessage,
        public array $missingCredentials,
        public bool $isBlockedExternal,
        public int $accountCount,
        public ?string $lastCheckedAt,
        public ?string $expiresAt,
    ) {}
}
