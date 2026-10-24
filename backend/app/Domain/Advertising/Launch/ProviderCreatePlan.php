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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'external_account_id' => $this->externalAccountId,
            'account_name' => $this->accountName,
            'account_currency' => $this->accountCurrency,
            'campaigns' => $this->campaigns,
            'ad_groups' => $this->adGroups,
            'ads' => $this->ads,
            'creatives' => $this->creatives,
            'budget_type' => $this->budgetType,
            'budget_minor' => $this->budgetMinor,
            'currency' => $this->currency,
            'starts_at' => $this->startsAt->format(DATE_ATOM),
            'ends_at' => $this->endsAt?->format(DATE_ATOM),
            'objective' => $this->objective,
            'conversion' => $this->conversion,
            'destination' => $this->destination,
            'initial_state' => $this->initialState,
            'delivers_by_default' => $this->deliversByDefault,
            'warnings' => $this->warnings,
            'blocked_fields' => $this->blockedFields,
            'payload' => $this->payload,
        ];
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $list = static fn (mixed $v): array => array_values(array_map('strval', (array) $v));

        return new self(
            provider: Provider::from((string) $a['provider']),
            externalAccountId: (string) $a['external_account_id'],
            accountName: (string) $a['account_name'],
            accountCurrency: isset($a['account_currency']) ? (string) $a['account_currency'] : null,
            campaigns: (int) $a['campaigns'],
            adGroups: (int) $a['ad_groups'],
            ads: (int) $a['ads'],
            creatives: array_values(array_map(static fn (mixed $c): array => ['id' => (string) ((array) $c)['id'], 'title' => (string) ((array) $c)['title']], (array) ($a['creatives'] ?? []))),
            budgetType: (string) $a['budget_type'],
            budgetMinor: (int) $a['budget_minor'],
            currency: (string) $a['currency'],
            startsAt: new DateTimeImmutable((string) $a['starts_at']),
            endsAt: isset($a['ends_at']) ? new DateTimeImmutable((string) $a['ends_at']) : null,
            objective: isset($a['objective']) ? (string) $a['objective'] : null,
            conversion: isset($a['conversion']) ? (string) $a['conversion'] : null,
            destination: isset($a['destination']) ? (string) $a['destination'] : null,
            initialState: (string) $a['initial_state'],
            deliversByDefault: (bool) ($a['delivers_by_default'] ?? false),
            warnings: $list($a['warnings'] ?? []),
            blockedFields: $list($a['blocked_fields'] ?? []),
            payload: (array) ($a['payload'] ?? []),
        );
    }
}
