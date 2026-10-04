<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Project;

use App\Application\Project\DTO\ProjectInput;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProjectRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            // The domain enforces this too; validating here gives a field-level error.
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function baseInput(string $clientId): ProjectInput
    {
        return new ProjectInput(
            clientId: $clientId,
            name: $this->string('name')->value(),
            currency: strtoupper($this->string('currency')->value()),
            slug: $this->nullableString('slug'),
            description: $this->nullableString('description'),
            startsOn: $this->nullableString('starts_on'),
            endsOn: $this->nullableString('ends_on'),
            isDemo: $this->boolean('is_demo'),
        );
    }
}
