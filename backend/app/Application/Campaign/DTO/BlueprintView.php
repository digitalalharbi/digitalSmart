<?php

declare(strict_types=1);

namespace App\Application\Campaign\DTO;

use DateTimeImmutable;

final readonly class BlueprintView
{
    /**
     * @param  list<array{external_account_id: string, provider: string, name: string, currency: string|null}>  $targets
     * @param  list<array{id: string, title: string, state: string}>  $creatives
     * @param  array{id: string, name: string, destination_url: string}|null  $trackingLink
     * @param  array{id: string, name: string}|null  $conversion
     * @param  array<string, mixed>  $targeting
     * @param  array<string, mixed>  $placement
     * @param  array<string, mixed>  $adCopy
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public string $projectId,
        public string $projectName,
        public string $name,
        public string $objective,
        public string $budgetType,
        public int $budgetMinor,
        public string $currency,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public array $targets,
        public ?array $trackingLink,
        public ?string $landingUrl,
        public ?array $conversion,
        public array $targeting,
        public array $placement,
        public array $adCopy,
        public array $creatives,
        public string $status,
        public int $version,
        public ?string $statusReason,
        public ?string $createdBy,
        public ?string $submittedBy,
        public ?DateTimeImmutable $submittedAt,
        public ?string $approvedBy,
        public ?DateTimeImmutable $approvedAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** Where people will land: the tracking link's destination when one is chosen, otherwise the address given. */
    public function destination(): ?string
    {
        return $this->trackingLink['destination_url'] ?? $this->landingUrl;
    }
}
