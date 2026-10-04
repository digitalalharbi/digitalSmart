<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Project;

use App\Domain\Access\SystemRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignMemberRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid'],
            'role' => ['required', 'string', Rule::enum(SystemRole::class)],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
