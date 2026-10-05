<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Integration;

use Illuminate\Foundation\Http\FormRequest;

final class BindAccountRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['external_account_id' => ['required', 'uuid']];
    }

    public function authorize(): bool
    {
        return true;
    }
}
