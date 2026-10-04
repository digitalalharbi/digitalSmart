<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Project;

use App\Domain\Project\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeProjectStatusRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::enum(ProjectStatus::class)]];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function status(): ProjectStatus
    {
        return ProjectStatus::from($this->string('status')->value());
    }
}
