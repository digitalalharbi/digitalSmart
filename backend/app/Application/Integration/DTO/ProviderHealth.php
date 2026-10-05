<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

use App\Domain\Integration\ConnectionStatus;

/** The outcome of a health check, reported rather than thrown. */
final readonly class ProviderHealth
{
    private function __construct(
        public bool $healthy,
        public ConnectionStatus $status,
        public ?string $message = null,
    ) {}

    public static function healthy(): self
    {
        return new self(true, ConnectionStatus::Connected);
    }

    public static function notConfigured(string $message): self
    {
        return new self(false, ConnectionStatus::NotConfigured, $message);
    }

    public static function awaitingAuthorisation(string $message): self
    {
        return new self(false, ConnectionStatus::Configured, $message);
    }

    public static function failed(ConnectionStatus $status, string $message): self
    {
        return new self(false, $status, $message);
    }
}
