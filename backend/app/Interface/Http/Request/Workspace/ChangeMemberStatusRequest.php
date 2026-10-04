<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Workspace;

use App\Domain\Workspace\MembershipStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeMemberStatusRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::enum(MembershipStatus::class)]];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function status(): MembershipStatus
    {
        return MembershipStatus::from($this->string('status')->value());
    }
}
