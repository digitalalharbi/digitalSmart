<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Dashboard;

use App\Application\Dashboard\DTO\SavedViewView;

final readonly class SavedViewResource
{
    /** @return array<string, mixed> */
    public static function toArray(SavedViewView $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'description' => $view->description,
            'visibility' => $view->visibility->value,
            'visibility_label' => $view->visibility->label(),
            'query' => $view->query,
            'is_default' => $view->isDefault,
            'project_id' => $view->projectId,
            'project_name' => $view->projectName,
            'author_name' => $view->authorName,
            /*
             * So the interface offers edit and delete only where they would work.
             * Hiding them is a courtesy; the server refuses either way.
             */
            'can_manage' => $view->isOwnedByActor,
            'last_used_at' => $view->lastUsedAt,
            'updated_at' => $view->updatedAt,
        ];
    }
}
