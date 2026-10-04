<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Client;

use App\Domain\Client\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeClientStatusRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(ClientStatus::class)],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function status(): ClientStatus
    {
        return ClientStatus::from($this->string('status')->value());
    }
}
