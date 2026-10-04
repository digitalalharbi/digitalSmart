<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Identity;

use Illuminate\Foundation\Http\FormRequest;

final class LogInRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
