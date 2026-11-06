<?php

declare(strict_types=1);

namespace App\Application\Influencers\DTO;

final readonly class InfluencerInput
{
    public function __construct(
        public string $clientId,
        public string $name,
        public ?string $handle = null,
        public ?string $platform = null,
        public ?string $notes = null,
        public string $status = 'active',
    ) {}
}
