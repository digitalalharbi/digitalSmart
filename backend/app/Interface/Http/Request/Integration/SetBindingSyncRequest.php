<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Integration;

use Illuminate\Foundation\Http\FormRequest;

final class SetBindingSyncRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['sync_enabled' => ['required', 'boolean']];
    }

    public function authorize(): bool
    {
        return true;
    }
}
