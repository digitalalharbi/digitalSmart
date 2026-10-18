<?php

declare(strict_types=1);

namespace App\Application\Agents\DTO;

/**
 * Something the Analyst saw, with the figures it rests on and the target it compared against.
 *
 * A finding is a fact about a campaign, not advice: what to do about it is another agent's job and then a person's
 * decision.
 */
final readonly class Finding
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $code,
        public string $campaignId,
        public string $campaignName,
        public ?string $projectId,
        public ?string $clientId,
        /** How far past the target, as a ratio: 2.0 means twice the cost it should be. */
        public float $severity,
        public ?int $budgetMinor,
        public array $evidence,
    ) {}
}
