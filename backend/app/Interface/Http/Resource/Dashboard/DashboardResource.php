<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Dashboard;

use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Dashboard\DTO\WidgetView;
use App\Domain\Dashboard\GridPlacement;

final readonly class DashboardResource
{
    /** @return array<string, mixed> */
    public static function toArray(DashboardView $dashboard): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'description' => $dashboard->description,
            'visibility' => $dashboard->visibility->value,
            'visibility_label' => $dashboard->visibility->label(),
            'filters' => $dashboard->filters,
            'is_default' => $dashboard->isDefault,
            'project_id' => $dashboard->projectId,
            'project_name' => $dashboard->projectName,
            'author_name' => $dashboard->authorName,
            'can_manage' => $dashboard->isOwnedByActor,
            // Published so the client lays out against the same grid the server
            // validates against, rather than a second copy of the number.
            'grid_columns' => GridPlacement::COLUMNS,
            'widgets' => array_map(
                static fn (WidgetView $widget): array => self::widget($widget),
                $dashboard->widgets,
            ),
            'updated_at' => $dashboard->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function widget(WidgetView $widget): array
    {
        return [
            'id' => $widget->id,
            'title' => $widget->title,
            'type' => $widget->type->value,
            'type_label' => $widget->type->label(),
            'metrics' => $widget->metrics,
            'dimension' => $widget->dimension?->value,
            /*
             * Null means "inherit the dashboard's filters"; `{}` means "override with
             * nothing". Two different instructions, so the null is preserved rather
             * than normalised to an empty object.
             */
            'filter_overrides' => $widget->filterOverrides,
            'column' => $widget->column,
            'row' => $widget->row,
            'width' => $widget->width,
            'height' => $widget->height,
            'options' => $widget->options,
        ];
    }
}
