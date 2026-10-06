<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Measurement;

use App\Application\Measurement\DTO\MeasurementSourceInput;
use App\Domain\Integration\Provider;
use App\Domain\Measurement\MeasurementSourceKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a measurement source's shape.
 *
 * Shape only. Whether the provider's category matches the kind — whether a store can be
 * registered as an analytics property — is a product rule and lives in the use case, where
 * the registry can answer it.
 */
final class MeasurementSourceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'kind' => ['required', Rule::in(MeasurementSourceKind::values())],
            // Nullable: a website form or a typed figure has no platform behind it.
            'provider' => ['sometimes', 'nullable', Rule::in(Provider::values())],
            'external_id' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:160'],
            'provider_connection_id' => ['sometimes', 'nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): MeasurementSourceInput
    {
        $kind = MeasurementSourceKind::tryFrom($this->string('kind')->value())
            ?? MeasurementSourceKind::Manual;

        return new MeasurementSourceInput(
            clientId: $this->string('client_id')->value(),
            kind: $kind,
            externalId: trim($this->string('external_id')->value()),
            name: trim($this->string('name')->value()),
            provider: Provider::tryFrom($this->string('provider')->value()),
            projectId: $this->string('project_id')->value() ?: null,
            providerConnectionId: $this->string('provider_connection_id')->value() ?: null,
            isActive: ! $this->has('is_active') || $this->boolean('is_active'),
        );
    }
}
