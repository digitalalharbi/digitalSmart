<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Integration;

use App\Domain\Integration\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConfigureConnectionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'credentials' => ['required', 'array'],
            // Only keys the provider actually needs are accepted, so a typo
            // cannot quietly store a credential nothing will ever read.
            'credentials.*' => ['required', 'string', 'max:4096'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $provider = $this->provider();
            $allowed = $provider->requiredCredentialKeys();

            /** @var array<string, mixed> $credentials */
            $credentials = (array) $this->input('credentials', []);

            foreach (array_keys($credentials) as $key) {
                if (! in_array($key, $allowed, true)) {
                    $validator->errors()->add(
                        'credentials',
                        sprintf('"%s" is not a credential %s uses. Expected: %s.',
                            (string) $key, $provider->label(), implode(', ', $allowed)),
                    );
                }
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }

    public function provider(): Provider
    {
        return Provider::from((string) $this->route('provider'));
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = (array) $this->input('credentials', []);

        $values = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, list<mixed>> */
    public static function providerRule(): array
    {
        return ['provider' => ['required', Rule::enum(Provider::class)]];
    }
}
