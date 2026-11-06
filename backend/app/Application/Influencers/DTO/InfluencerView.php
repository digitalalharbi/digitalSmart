<?php

declare(strict_types=1);

namespace App\Application\Influencers\DTO;

use DateTimeImmutable;

/** One influencer, and what the links made for them brought — valid traffic only, and nothing about money. */
final readonly class InfluencerView
{
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $name,
        public ?string $handle,
        public ?string $platform,
        public ?string $notes,
        public string $status,
        public DateTimeImmutable $createdAt,
        public int $links = 0,
        public int $clicks = 0,
        public int $visitors = 0,
    ) {}

    public function withTotals(int $links, int $clicks, int $visitors): self
    {
        return new self(
            $this->id, $this->clientId, $this->clientName, $this->name, $this->handle, $this->platform, $this->notes,
            $this->status, $this->createdAt, $links, $clicks, $visitors,
        );
    }
}
