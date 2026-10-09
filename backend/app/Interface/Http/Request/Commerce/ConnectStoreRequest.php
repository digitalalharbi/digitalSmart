<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Commerce;

use App\Domain\Integration\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the shape of a store connection.
 *
 * Shape only. Whether the provider is a commerce platform this product can read, whether it is connected, and
 * whether the merchant is one the connection can still see are product rules and live in `ConnectStore`, where
 * the registry and the credentials can answer them.
 *
 * There is deliberately **no name, currency, domain or timezone**: those are read back from the platform. A
 * typed currency would be the most expensive field in this product to get wrong, because every amount from that
 * store is stored in it and nothing downstream could detect the mistake.
 */
final class ConnectStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(Provider::values())],
            'external_id' => ['required', 'string', 'max:191'],
            'client_id' => ['required', 'uuid'],
        ];
    }

    public function provider(): Provider
    {
        /* Validated above, so `from` cannot throw here. */
        return Provider::from($this->string('provider')->value());
    }
}
