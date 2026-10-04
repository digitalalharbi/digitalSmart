<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Workspace;

use App\Domain\Access\SystemRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeMemberRoleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::enum(SystemRole::class)]];
    }

    public function authorize(): bool
    {
        return true;
    }
}
