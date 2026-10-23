<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use App\Domain\Tracking\LinkStatus;
use DateTimeImmutable;

final readonly class TrackingLinkView
{
    /**
     * @param  array<string, string>  $utm
     * @param  array<string, string>  $customParams
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $projectId,
        public string $projectName,
        public string $publicToken,
        public string $name,
        public ?string $platform,
        public ?string $campaign,
        public ?string $adGroup,
        public ?string $ad,
        public ?string $creative,
        public string $channel,
        public string $destinationUrl,
        public array $utm,
        public array $customParams,
        public ?string $objective,
        public LinkStatus $status,
        public ?DateTimeImmutable $expiresAt,
        public ?string $notes,
        public DateTimeImmutable $createdAt,
        public ?LinkCounts $counts = null,
    ) {}

    public function withCounts(LinkCounts $counts): self
    {
        return new self(
            $this->id, $this->clientId, $this->clientName, $this->projectId, $this->projectName, $this->publicToken,
            $this->name, $this->platform, $this->campaign, $this->adGroup, $this->ad, $this->creative, $this->channel,
            $this->destinationUrl, $this->utm, $this->customParams, $this->objective, $this->status, $this->expiresAt,
            $this->notes, $this->createdAt, $counts,
        );
    }
}
