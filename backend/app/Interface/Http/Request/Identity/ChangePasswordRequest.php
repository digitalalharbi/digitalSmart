<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Identity;

use App\Application\Identity\Command\ChangePassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Whether the current password is right, and whether the new one is different, are the use case's
 * business — those are rules about an account rather than about a request body, and they carry audit entries.
 *
 * `confirmed` is here rather than in the use case for the opposite reason: a mistyped confirmation is a mistake
 * about a form, nothing happened, and nothing needs recording.
 */
final class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            /*
             * The minimum lives on the use case, so the rule and the refusal cannot disagree — a form that
             * allowed eleven characters and a use case that refused them would be a confusing afternoon.
             */
            'password' => ['required', 'string', 'min:'.ChangePassword::MINIMUM_LENGTH, 'confirmed'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two new passwords do not match.',
        ];
    }
}
