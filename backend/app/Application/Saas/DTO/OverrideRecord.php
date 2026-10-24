<?php

declare(strict_types=1);

namespace App\Application\Saas\DTO;

use DateTimeImmutable;

final readonly class OverrideRecord
{
    public function __construct(
        public string $kind,
        public string $key,
        public string $value,
        public ?string $previousValue,
        public string $reason,
        public string $changedBy,
        public DateTimeImmutable $at,
    ) {}
}
