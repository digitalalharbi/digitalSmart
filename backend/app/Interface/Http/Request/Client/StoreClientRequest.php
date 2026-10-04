<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Client;

final class StoreClientRequest extends ClientRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Only settable at creation: a record cannot become demo data, or
            // stop being it, after people have started trusting its numbers.
            'is_demo' => ['sometimes', 'boolean'],
        ];
    }
}
