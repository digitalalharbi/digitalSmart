<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Project;

use App\Application\Project\DTO\ProjectInput;

final class StoreProjectRequest extends ProjectRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_id' => ['required', 'uuid'],
            'is_demo' => ['sometimes', 'boolean'],
        ];
    }

    public function toInput(): ProjectInput
    {
        return $this->baseInput($this->string('client_id')->value());
    }
}
