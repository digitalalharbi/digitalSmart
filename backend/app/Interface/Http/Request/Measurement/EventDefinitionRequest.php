<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Measurement;

use App\Application\Measurement\DTO\EventDefinitionInput;
use App\Domain\Measurement\NormalizedEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a conversion definition and its mappings.
 *
 * The mappings are `present` rather than `required`: an empty array is a coherent request
 * shape, and refusing it belongs in the use case where the message can say *why* — nothing
 * would ever count towards the conversion — rather than as "the mappings field is required".
 */
final class EventDefinitionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'normalized_event' => ['required', Rule::in(NormalizedEvent::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_conversion' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'expects_value' => ['sometimes', 'boolean'],
            'mappings' => ['present', 'array', 'max:40'],
            /*
             * Provider event names are snake_case by convention everywhere this product
             * integrates, but the pattern is deliberately permissive about case and dots: GA4
             * allows both, and refusing a name a client actually fires would make the mapping
             * unusable for them.
             */
            'mappings.*.raw_event_name' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'mappings.*.measurement_source_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function toInput(): EventDefinitionInput
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = $this->input('mappings', []);
        $mappings = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['raw_event_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $sourceId = $entry['measurement_source_id'] ?? null;

            $mappings[] = [
                'raw_event_name' => $name,
                'measurement_source_id' => is_string($sourceId) && $sourceId !== '' ? $sourceId : null,
            ];
        }

        return new EventDefinitionInput(
            clientId: $this->string('client_id')->value(),
            name: trim($this->string('name')->value()),
            normalizedEvent: NormalizedEvent::tryFrom($this->string('normalized_event')->value())
                ?? NormalizedEvent::Custom,
            projectId: $this->string('project_id')->value() ?: null,
            description: $this->filled('description') ? trim($this->string('description')->value()) : null,
            isConversion: ! $this->has('is_conversion') || $this->boolean('is_conversion'),
            isPrimary: $this->boolean('is_primary'),
            expectsValue: $this->boolean('expects_value'),
            mappings: $mappings,
        );
    }
}
