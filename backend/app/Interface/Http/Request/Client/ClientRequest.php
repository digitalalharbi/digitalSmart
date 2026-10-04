<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Client;

use App\Application\Client\DTO\ClientInput;
use Illuminate\Foundation\Http\FormRequest;

/** Shared validation and input mapping for creating and updating a client. */
abstract class ClientRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'primary_contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'primary_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'primary_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers and single hyphens.',
        ];
    }

    public function authorize(): bool
    {
        /*
         * Authorization belongs to the Application guard, which knows the
         * actor's workspace permissions. A form request cannot see that, and
         * splitting the decision across two layers is how gaps appear.
         */
        return true;
    }

    public function toInput(): ClientInput
    {
        return new ClientInput(
            name: $this->string('name')->value(),
            slug: $this->nullableString('slug'),
            legalName: $this->nullableString('legal_name'),
            industry: $this->nullableString('industry'),
            website: $this->nullableString('website'),
            primaryContactName: $this->nullableString('primary_contact_name'),
            primaryContactEmail: $this->nullableString('primary_contact_email'),
            primaryContactPhone: $this->nullableString('primary_contact_phone'),
            defaultCurrency: $this->nullableString('default_currency'),
            notes: $this->nullableString('notes'),
            isDemo: $this->boolean('is_demo'),
        );
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
}
