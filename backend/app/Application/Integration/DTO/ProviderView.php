<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

/** A provider as the integrations page shows it: what it is, and where we stand with it. */
final readonly class ProviderView
{
    /**
     * @param  list<array{slug: string, label: string, is_write: bool}>  $capabilities
     * @param  list<string>  $missingCredentials
     */
    public function __construct(
        public string $provider,
        public string $label,
        public string $authMethod,
        public array $capabilities,
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
