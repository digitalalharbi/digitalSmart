<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

use App\Domain\Integration\Provider;
use DateTimeImmutable;

/**
 * Exactly what would be written to one ad account: how many campaigns, ad groups and ads, with what budget, schedule, objective,
 * conversion, destination and initial state — and the platform payload that would carry it. Nothing here is sent; PR G sends it,
 * after approval, through the queue.
 */
final readonly class ProviderCreatePlan
{
    /**
     * @param  list<array{id: string, title: string}>  $creatives
     * @param  list<string>  $warnings
     * @param  list<string>  $blockedFields
     * @param  array<string, mixed>  $payload  the platform's own field names, from its documentation
     */
    public function __construct(
        public Provider $provider,
        public string $externalAccountId,
        public string $accountName,
        public ?string $accountCurrency,
        public int $campaigns,
        public int $adGroups,
        public int $ads,
        public array $creatives,
        public string $budgetType,
        public int $budgetMinor,
        public string $currency,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public ?string $objective,
        public ?string $conversion,
        public ?string $destination,
        /** How the platform is told not to deliver, from its documentation. */
        public string $initialState,
        public bool $deliversByDefault,
        public array $warnings,
        public array $blockedFields,
        public array $payload,
    ) {}
}
