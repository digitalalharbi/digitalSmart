<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Project;

use App\Application\Project\DTO\ProjectInput;

/** A project cannot be moved to another client: its history belongs where it was earned. */
final class UpdateProjectRequest extends ProjectRequest
{
    public function toInput(string $currentClientId): ProjectInput
    {
        return $this->baseInput($currentClientId);
    }
}
