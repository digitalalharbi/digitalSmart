<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\DTO\DashboardInput;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Dashboard\DTO\WidgetInput;
use App\Application\Dashboard\DTO\WidgetView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Analytics\Dimension;
use App\Domain\Dashboard\Visibility;
use App\Infrastructure\Persistence\Eloquent\Model\Dashboard;
use App\Infrastructure\Persistence\Eloquent\Model\DashboardWidget;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDashboardRepository implements DashboardRepository
{
    public function __construct(private Clock $clock) {}

    /** @return list<DashboardView> */
    public function visibleTo(string $workspaceId, string $actorId, ?array $visibleProjectIds): array
    {
        $dashboards = $this->visibleQuery($workspaceId, $actorId, $visibleProjectIds)
            ->with(['widgets', 'author:id,name', 'project:id,name'])
            ->orderByDesc('is_default')
            ->orderByDesc('last_viewed_at')
            ->orderBy('name')
            ->get();

        return array_values(
            $dashboards->map(fn (Dashboard $d): DashboardView => $this->toView($d, $actorId))->all(),
        );
    }

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, string $actorId, ?array $visibleProjectIds, string $id): ?DashboardView
    {
        $dashboard = $this->visibleQuery($workspaceId, $actorId, $visibleProjectIds)
            ->with(['widgets', 'author:id,name', 'project:id,name'])
            ->whereKey($id)
            ->first();

        return $dashboard instanceof Dashboard ? $this->toView($dashboard, $actorId) : null;
    }

    public function create(string $workspaceId, string $actorId, DashboardInput $input): DashboardView
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $input): DashboardView {
            $dashboard = Dashboard::query()->create([
                'workspace_id' => $workspaceId,
                'created_by' => $actorId,
                'project_id' => $input->visibility === Visibility::Project ? $input->projectId : null,
                'name' => $input->name,
                'description' => $input->description,
                'visibility' => $input->visibility,
                'filters' => $input->filters,
                'last_viewed_at' => $this->clock->now(),
            ]);

            $this->replaceWidgets($dashboard, $input->widgets ?? []);

            return $this->toView($this->reload($dashboard), $actorId);
        });
    }

    public function update(string $workspaceId, string $id, DashboardInput $input): DashboardView
    {
        return DB::transaction(function () use ($workspaceId, $id, $input): DashboardView {
            /** @var Dashboard $dashboard */
            $dashboard = Dashboard::query()
                ->where('workspace_id', $workspaceId)
                ->whereKey($id)
                ->firstOrFail();

            $dashboard->update([
                'name' => $input->name,
                'description' => $input->description,
                'visibility' => $input->visibility,
                'project_id' => $input->visibility === Visibility::Project ? $input->projectId : null,
                'filters' => $input->filters,
            ]);

            /*
             * Null widgets means "leave the canvas alone" — renaming a dashboard or
             * changing its audience should not require resending every widget. An
             * empty array means "the canvas is now empty", which is a different
             * instruction and has to stay distinguishable.
             */
            if ($input->widgets !== null) {
                $this->replaceWidgets($dashboard, $input->widgets);
            }

            return $this->toView($this->reload($dashboard), (string) $dashboard->getAttribute('created_by'));
        });
    }

    public function delete(string $workspaceId, string $id): void
    {
        // Widgets cascade by the foreign key rather than by this method remembering.
        Dashboard::query()->where('workspace_id', $workspaceId)->whereKey($id)->delete();
    }

    public function duplicate(string $workspaceId, string $actorId, string $id, string $name): DashboardView
    {
        return DB::transaction(function () use ($workspaceId, $actorId, $id, $name): DashboardView {
            /** @var Dashboard $source */
            $source = Dashboard::query()
                ->with('widgets')
                ->where('workspace_id', $workspaceId)
                ->whereKey($id)
                ->firstOrFail();

            $copy = Dashboard::query()->create([
                'workspace_id' => $workspaceId,
                'created_by' => $actorId,
                'name' => $name,
                'description' => $source->getAttribute('description'),
                /*
                 * Private, whatever the original was. Inheriting a shared audience
                 * would publish a draft the moment it was created, to people who did
                 * not ask for it.
                 */
                'visibility' => Visibility::Private,
                'project_id' => null,
                'filters' => $source->getAttribute('filters'),
                'last_viewed_at' => $this->clock->now(),
            ]);

            foreach ($source->widgets as $widget) {
                DashboardWidget::query()->create([
                    'dashboard_id' => $copy->getKey(),
                    'title' => $widget->getAttribute('title'),
                    'type' => $widget->getAttribute('type'),
                    'metrics' => $widget->getAttribute('metrics'),
                    'dimension' => $widget->getAttribute('dimension'),
                    'filter_overrides' => $widget->getAttribute('filter_overrides'),
                    'grid_column' => $widget->getAttribute('grid_column'),
                    'grid_row' => $widget->getAttribute('grid_row'),
                    'grid_width' => $widget->getAttribute('grid_width'),
                    'grid_height' => $widget->getAttribute('grid_height'),
                    'options' => $widget->getAttribute('options'),
                ]);
            }

            return $this->toView($this->reload($copy), $actorId);
        });
    }

    public function nameTaken(string $workspaceId, string $actorId, string $name, ?string $exceptId = null): bool
    {
        return Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->where('name', $name)
            ->when($exceptId !== null, static fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool
    {
        return Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->whereKey($id)
            ->exists();
    }

    public function makeDefault(string $workspaceId, string $actorId, string $id): void
    {
        Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->update(['is_default' => false]);

        Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->whereKey($id)
            ->update(['is_default' => true]);
    }

    public function touchLastViewed(string $workspaceId, string $id): void
    {
        Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->update(['last_viewed_at' => $this->clock->now()]);
    }

    /**
     * Replace the widget set wholesale.
     *
     * Deleted and reinserted rather than diffed. The ids change, which is acceptable
     * because nothing references a widget from outside its dashboard — and the
     * alternative is reconciling a set of grid positions, where a missed case leaves
     * a widget the reader deleted still on the canvas.
     *
     * @param  list<WidgetInput>  $widgets
     */
    private function replaceWidgets(Dashboard $dashboard, array $widgets): void
    {
        DashboardWidget::query()->where('dashboard_id', $dashboard->getKey())->delete();

        foreach ($widgets as $widget) {
            DashboardWidget::query()->create([
                'dashboard_id' => $dashboard->getKey(),
                'title' => $widget->title,
                'type' => $widget->type,
                'metrics' => array_map(static fn (object $metric): string => $metric->value, $widget->metrics),
                'dimension' => $widget->dimension?->value,
                'filter_overrides' => $widget->filterOverrides,
                'grid_column' => $widget->placement->column,
                'grid_row' => $widget->placement->row,
                'grid_width' => $widget->placement->width,
                'grid_height' => $widget->placement->height,
                'options' => $widget->options,
            ]);
        }
    }

    private function reload(Dashboard $dashboard): Dashboard
    {
        return $dashboard->fresh(['widgets', 'author', 'project']) ?? $dashboard;
    }

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<Dashboard>
     */
    private function visibleQuery(string $workspaceId, string $actorId, ?array $visibleProjectIds): Builder
    {
        return Dashboard::query()
            ->where('workspace_id', $workspaceId)
            ->where(function (Builder $query) use ($actorId, $visibleProjectIds): void {
                $query->where('created_by', $actorId)
                    ->orWhere('visibility', Visibility::Workspace->value);

                if ($visibleProjectIds === null) {
                    $query->orWhere('visibility', Visibility::Project->value);
                } elseif ($visibleProjectIds !== []) {
                    $query->orWhere(function (Builder $scoped) use ($visibleProjectIds): void {
                        $scoped->where('visibility', Visibility::Project->value)
                            ->whereIn('project_id', $visibleProjectIds);
                    });
                }
            });
    }

    private function toView(Dashboard $dashboard, string $actorId): DashboardView
    {
        $filters = $dashboard->getAttribute('filters');
        $updated = $dashboard->getAttribute('updated_at');

        /** @var list<WidgetView> $widgets */
        $widgets = $dashboard->widgets
            ->map(function (DashboardWidget $widget): WidgetView {
                $metrics = $widget->getAttribute('metrics');
                $dimension = $widget->getAttribute('dimension');
                $overrides = $widget->getAttribute('filter_overrides');
                $options = $widget->getAttribute('options');

                return new WidgetView(
                    id: (string) $widget->getKey(),
                    title: $widget->getAttribute('title'),
                    type: $widget->getAttribute('type'),
                    metrics: is_array($metrics) ? array_values(array_map('strval', $metrics)) : [],
                    dimension: is_string($dimension) ? Dimension::tryFrom($dimension) : null,
                    // Preserved as null when inherited; see the model's cast note.
                    filterOverrides: is_array($overrides) ? $overrides : null,
                    column: (int) $widget->getAttribute('grid_column'),
                    row: (int) $widget->getAttribute('grid_row'),
                    width: (int) $widget->getAttribute('grid_width'),
                    height: (int) $widget->getAttribute('grid_height'),
                    options: is_array($options) ? $options : [],
                );
            })
            ->values()
            ->all();

        return new DashboardView(
            id: (string) $dashboard->getKey(),
            name: (string) $dashboard->getAttribute('name'),
            description: $dashboard->getAttribute('description'),
            visibility: $dashboard->getAttribute('visibility'),
            filters: is_array($filters) ? $filters : [],
            isDefault: (bool) $dashboard->getAttribute('is_default'),
            projectId: $dashboard->getAttribute('project_id'),
            projectName: $dashboard->project?->getAttribute('name'),
            authorName: $dashboard->author?->getAttribute('name'),
            isOwnedByActor: (string) $dashboard->getAttribute('created_by') === $actorId,
            widgets: $widgets,
            updatedAt: $updated instanceof DateTimeInterface ? $updated->format(DateTimeInterface::ATOM) : '',
        );
    }
}
