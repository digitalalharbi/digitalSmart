<?php

declare(strict_types=1);

namespace App\Infrastructure\Leads\Mapper;

use App\Application\Leads\DTO\CrmContact;
use App\Application\Leads\DTO\CrmDeal;
use App\Application\Leads\DTO\CrmPipeline;
use App\Application\Leads\DTO\CrmPipelineStage;
use App\Domain\Leads\CrmStageMeaning;
use DateTimeImmutable;
use Exception;

/**
 * HubSpot's JSON into this product's own shapes.
 *
 * The mapper is where every assumption about a provider's response lives, and three of HubSpot's are worth
 * naming because each is a defect waiting to be written.
 *
 * **Everything in `properties` is a string.** `amount` arrives as `"48000.00"`, `probability` as `"0.2"`,
 * `isClosed` as `"true"` — not as a number and not as a boolean. Code that compared `$metadata['isClosed'] ===
 * true` would find every stage open, and code that treated `amount` as a float would be lucky rather than right.
 *
 * **An empty string is not a zero.** A deal with no amount publishes `"amount": ""`, and reading that as 0.00
 * would put a deal worth nothing into a revenue figure. Null means "the CRM did not say".
 *
 * **A stage's meaning is never read from its label.** The fixture carries a stage called "Won" whose metadata says
 * `isClosed: false, probability: 0.9` — a real pipeline somebody built by hand — and this mapper reports it as
 * **open**, because that is what the CRM said about it. A product that trusted the word "Won" there would report
 * a sale that has not happened. See `CrmStageMeaning`.
 *
 * Amounts are converted to minor units at a fixed exponent of two. That is a limitation rather than a rule:
 * HubSpot publishes a deal's currency code but not its exponent, and the currencies this product sees — SAR, AED,
 * USD — all use two. A zero-decimal currency would be read a hundredfold too large, which is why the conversion
 * is in one place with this note on it rather than scattered.
 */
final readonly class HubSpotCrmMapper
{
    /** What every currency this product has seen uses. See the note above. */
    private const int MINOR_UNIT_EXPONENT = 2;

    /**
     * Pipelines from a `GET /crm/v3/pipelines/deals` response.
     *
     * @param  array<string, mixed>  $response
     * @return list<CrmPipeline>
     */
    public function pipelines(array $response): array
    {
        $pipelines = [];

        foreach ($this->listOf($response, 'results') as $raw) {
            $stages = [];

            foreach ($this->listOf($raw, 'stages') as $stage) {
                /** @var array<string, mixed> $metadata */
                $metadata = is_array($stage['metadata'] ?? null) ? $stage['metadata'] : [];

                $isClosed = $this->boolean($metadata['isClosed'] ?? null);
                $probability = $this->decimal($metadata['probability'] ?? null);

                $stages[] = new CrmPipelineStage(
                    id: $this->text($stage['id'] ?? null) ?? '',
                    /* Shown, never interpreted. */
                    label: $this->text($stage['label'] ?? null) ?? '',
                    displayOrder: (int) ($stage['displayOrder'] ?? 0),
                    meaning: CrmStageMeaning::fromStageMetadata($isClosed, $probability),
                    probability: $probability,
                    isClosed: $isClosed,
                );
            }

            $pipelines[] = new CrmPipeline(
                id: $this->text($raw['id'] ?? null) ?? '',
                label: $this->text($raw['label'] ?? null) ?? '',
                displayOrder: (int) ($raw['displayOrder'] ?? 0),
                isArchived: $this->boolean($raw['archived'] ?? null) === true,
                stages: $stages,
            );
        }

        return $pipelines;
    }

    /**
     * Deals from a `GET /crm/v3/objects/deals` response.
     *
     * The associated contacts arrive on each deal under `_contacts` in a sandbox fixture; against the live API
     * they are a second request to `/crm/v4/objects/deal/{id}/associations/contact` followed by a batch read of
     * the contacts. The shape this mapper reads is the same either way, which is the point of the fixture
     * carrying them inline: the adapter does the fetching and the mapper does the reading.
     *
     * @param  array<string, mixed>  $response
     * @return list<CrmDeal>
     */
    public function deals(array $response): array
    {
        $deals = [];

        foreach ($this->listOf($response, 'results') as $raw) {
            /** @var array<string, mixed> $properties */
            $properties = is_array($raw['properties'] ?? null) ? $raw['properties'] : [];

            $amount = $this->decimal($properties['amount'] ?? null);

            $deals[] = new CrmDeal(
                id: $this->text($raw['id'] ?? null) ?? '',
                name: $this->text($properties['dealname'] ?? null) ?? 'Untitled deal',
                pipelineId: $this->text($properties['pipeline'] ?? null) ?? '',
                /* The internal id, which is what `dealstage` holds. The label lives on the stage. */
                stageId: $this->text($properties['dealstage'] ?? null) ?? '',
                /*
                 * Null where the CRM published nothing. A deal with no amount is ordinary — most are, until
                 * somebody quotes — and a zero would be a figure this product invented.
                 */
                amountMinor: $amount === null ? null : (int) round($amount * (10 ** self::MINOR_UNIT_EXPONENT)),
                currency: $amount === null ? null : $this->text($properties['deal_currency_code'] ?? null),
                createdAt: $this->moment($raw['createdAt'] ?? null) ?? new DateTimeImmutable('@0'),
                updatedAt: $this->moment($raw['updatedAt'] ?? null) ?? new DateTimeImmutable('@0'),
                closeDate: $this->moment($properties['closedate'] ?? null),
                contacts: $this->contacts($raw['_contacts'] ?? null),
            );
        }

        return $deals;
    }

    /**
     * Contacts from a batch read, or from the `_contacts` a fixture carries inline.
     *
     * @return list<CrmContact>
     */
    public function contacts(mixed $raw): array
    {
        $contacts = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $properties */
            $properties = is_array($entry['properties'] ?? null) ? $entry['properties'] : [];

            $first = $this->text($properties['firstname'] ?? null);
            $last = $this->text($properties['lastname'] ?? null);
            $name = trim(($first ?? '').' '.($last ?? ''));

            $contacts[] = new CrmContact(
                id: $this->text($entry['id'] ?? null) ?? '',
                /*
                 * Null rather than a half name padded with a space. A record showing " Nasser" reads as a
                 * defect, and a record showing nothing reads as what it is: a contact with no name on it.
                 */
                name: $name === '' ? null : $name,
                email: $this->text($properties['email'] ?? null),
                phone: $this->text($properties['phone'] ?? null),
            );
        }

        return $contacts;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function listOf(array $source, string $key): array
    {
        $rows = [];

        foreach (is_array($source[$key] ?? null) ? $source[$key] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** A trimmed string, or null where there was nothing — including where there was an empty string. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A decimal from a property, which HubSpot publishes as a string.
     *
     * Null for an empty string, which is how a deal with no amount arrives. Reading that as 0.00 would put a
     * deal worth nothing into a revenue figure.
     */
    private function decimal(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $text = $this->text($value);

        return $text === null || ! is_numeric($text) ? null : (float) $text;
    }

    /** A boolean from a property, which HubSpot publishes as the string "true" or "false". */
    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = $this->text($value);

        return match ($text) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    private function moment(mixed $value): ?DateTimeImmutable
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Exception) {
            /* A date this product cannot read is not a date. Never "now", which would be an invented fact. */
            return null;
        }
    }
}
