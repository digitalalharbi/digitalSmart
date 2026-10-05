<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Dashboard\DTO\SavedViewInput;
use App\Application\Dashboard\DTO\SavedViewView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Dashboard\Visibility;
use App\Infrastructure\Persistence\Eloquent\Model\SavedView;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentSavedViewRepository implements SavedViewRepository
{
    public function __construct(private Clock $clock) {}

    /** @return list<SavedViewView> */
    public function visibleTo(string $workspaceId, string $actorId, ?array $visibleProjectIds): array
    {
        $views = $this->visibleQuery($workspaceId, $actorId, $visibleProjectIds)
            ->with(['author:id,name', 'project:id,name'])
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->orderBy('name')
            ->get();

        return array_values(
            $views->map(fn (SavedView $view): SavedViewView => $this->toView($view, $actorId))->all(),
        );
    }

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, string $actorId, ?array $visibleProjectIds, string $id): ?SavedViewView
    {
        $view = $this->visibleQuery($workspaceId, $actorId, $visibleProjectIds)
            ->with(['author:id,name', 'project:id,name'])
            ->whereKey($id)
            ->first();

        return $view instanceof SavedView ? $this->toView($view, $actorId) : null;
    }

    public function create(string $workspaceId, string $actorId, SavedViewInput $input): SavedViewView
    {
        $view = SavedView::query()->create([
            'workspace_id' => $workspaceId,
            'created_by' => $actorId,
            'project_id' => $input->visibility === Visibility::Project ? $input->projectId : null,
            'name' => $input->name,
            'description' => $input->description,
            'visibility' => $input->visibility,
            'query' => $this->encodeQuery($input),
            'last_used_at' => $this->clock->now(),
        ]);

        return $this->toView($view->fresh(['author', 'project']) ?? $view, $actorId);
    }

    public function update(string $workspaceId, string $id, SavedViewInput $input): SavedViewView
    {
        /** @var SavedView $view */
        $view = SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->firstOrFail();

        $view->update([
            'name' => $input->name,
            'description' => $input->description,
            'visibility' => $input->visibility,
            // Cleared when the view stops being project-scoped, so a later change to
            // workspace visibility cannot leave a stale project attached.
            'project_id' => $input->visibility === Visibility::Project ? $input->projectId : null,
            'query' => $this->encodeQuery($input),
        ]);

        $actorId = (string) $view->getAttribute('created_by');

        return $this->toView($view->fresh(['author', 'project']) ?? $view, $actorId);
    }

    public function delete(string $workspaceId, string $id): void
    {
        SavedView::query()->where('workspace_id', $workspaceId)->whereKey($id)->delete();
    }

    public function nameTaken(string $workspaceId, string $actorId, string $name, ?string $exceptId = null): bool
    {
        return SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->where('name', $name)
            ->when($exceptId !== null, static fn (Builder $query): Builder => $query->whereKeyNot($exceptId))
            ->exists();
    }

    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool
    {
        return SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->whereKey($id)
            ->exists();
    }

    public function makeDefault(string $workspaceId, string $actorId, string $id): void
    {
        /*
         * Cleared first, then set. One default per person per workspace: two would
         * make the landing view arbitrary, which is worse than having none.
         */
        SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->update(['is_default' => false]);

        SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by', $actorId)
            ->whereKey($id)
            ->update(['is_default' => true]);
    }

    public function touchLastUsed(string $workspaceId, string $id): void
    {
        SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->update(['last_used_at' => $this->clock->now()]);
    }

    /**
     * Views the actor may see.
     *
     * Applied as a WHERE rather than filtered afterwards: a private view belonging to
     * someone else must never be in the result set at all, because a leaked name or
     * even a count is still a leak.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<SavedView>
     */
    private function visibleQuery(string $workspaceId, string $actorId, ?array $visibleProjectIds): Builder
    {
        return SavedView::query()
            ->where('workspace_id', $workspaceId)
            ->where(function (Builder $query) use ($actorId, $visibleProjectIds): void {
                // Their own, whatever its visibility.
                $query->where('created_by', $actorId)
                    ->orWhere('visibility', Visibility::Workspace->value);

                /*
                 * Project-scoped views, but only for projects this actor can see.
                 * A null list means unrestricted; an empty one means they are
                 * assigned to nothing, so no project-scoped view reaches them.
                 */
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

    /** @return array<string, mixed> */
    private function encodeQuery(SavedViewInput $input): array
    {
        $request = $input->query;

        return array_filter([
            'start' => $request->range->startDate(),
            'end' => $request->range->endDate(),
            'compare_start' => $request->compareTo?->startDate(),
            'compare_end' => $request->compareTo?->endDate(),
            'metrics' => array_map(static fn (object $metric): string => $metric->value, $request->metrics),
            'dimension' => $request->dimension?->value,
            'order_by' => $request->orderBy?->value,
            'limit' => $request->limit,
            'providers' => $request->filters->providers,
            'account_ids' => $request->filters->externalAccountIds,
            'campaign_ids' => $request->filters->campaignIds,
            'ad_group_ids' => $request->filters->adGroupIds,
            'ad_ids' => $request->filters->adIds,
            'project_ids' => $request->filters->projectIds,
            'client_id' => $request->filters->clientId,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function toView(SavedView $view, string $actorId): SavedViewView
    {
        $query = $view->getAttribute('query');
        $lastUsed = $view->getAttribute('last_used_at');
        $updated = $view->getAttribute('updated_at');

        return new SavedViewView(
            id: (string) $view->getKey(),
            name: (string) $view->getAttribute('name'),
            description: $view->getAttribute('description'),
            visibility: $view->getAttribute('visibility'),
            query: is_array($query) ? $query : [],
            isDefault: (bool) $view->getAttribute('is_default'),
            projectId: $view->getAttribute('project_id'),
            projectName: $view->project?->getAttribute('name'),
            authorName: $view->author?->getAttribute('name'),
            // So the interface can offer edit and delete only where they would work,
            // rather than letting the reader discover a 404 by trying.
            isOwnedByActor: (string) $view->getAttribute('created_by') === $actorId,
            lastUsedAt: $lastUsed instanceof DateTimeInterface ? $lastUsed->format(DateTimeInterface::ATOM) : null,
            updatedAt: $updated instanceof DateTimeInterface ? $updated->format(DateTimeInterface::ATOM) : '',
        );
    }
}
