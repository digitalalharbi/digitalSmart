<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

use App\Domain\Shared\ValueObject\ServiceStatus;

/** One probed dependency and what the probe observed. */
final readonly class ServiceHealth
{
    public function __construct(
        public string $name,
        public ServiceStatus $status,
        public ?string $detail = null,
    ) {}

    public static function ok(string $name, ?string $detail = null): self
    {
        return new self($name, ServiceStatus::Ok, $detail);
    }

    public static function degraded(string $name, string $detail): self
    {
        return new self($name, ServiceStatus::Degraded, $detail);
    }

    public static function down(string $name, string $detail): self
    {
        return new self($name, ServiceStatus::Down, $detail);
    }
}
