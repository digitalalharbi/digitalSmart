<?php

declare(strict_types=1);

namespace App\Application\Dashboard\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Dashboard\Contract\DashboardRepository;
use App\Application\Dashboard\DTO\DashboardInput;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Dashboard\DTO\WidgetInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Dashboard\Exception\InvalidPlacement;
use App\Domain\Dashboard\Exception\InvalidWidget;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;
use App\Domain\Dashboard\GridPlacement;
use App\Domain\Dashboard\Visibility;

/**
 * Create or replace a dashboard and its whole widget set.
 *
 * The widget set is replaced rather than merged. The canvas a reader sees is the
 * whole truth about the dashboard, so a widget they removed has to go — and diffing
 * a set of grid positions is a worse way to reach that than sending the set.
 *
 * Every widget is validated against its own type before anything is written: a
 * share-of-total across ninety dates draws fine and tells a reader nothing, and a
 * widget placed off the 12-column canvas disappears with nothing to say it was there.
 * Both are refused here rather than stored and discovered later.
 */
final readonly class SaveDashboard
{
    public function __construct(
        private DashboardRepository $dashboards,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(DashboardInput $input, ?string $id = null): DashboardView
    {
        $this->guard->ensure(Permission::SavedViewsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $actorId = $this->actor->userId()->value;

        $this->assertProjectScope($input);
        $this->assertWidgetsAreCoherent($input->widgets);

        if ($id === null) {
            $this->assertNameIsFree($workspaceId, $actorId, $input->name, null);

            $dashboard = $this->dashboards->create($workspaceId, $actorId, $input);
        } else {
            if (! $this->dashboards->isOwnedBy($workspaceId, $id, $actorId)) {
                throw ResourceNotFound::of('Dashboard');
            }

            $this->assertNameIsFree($workspaceId, $actorId, $input->name, $id);

            $dashboard = $this->dashboards->update($workspaceId, $id, $input);
        }

        if ($input->isDefault) {
            $this->dashboards->makeDefault($workspaceId, $actorId, $dashboard->id);
        }

        return $dashboard;
    }

    /**
     * Names are unique per owner, and the index says so whether or not we ask first.
     *
     * Asking turns an everyday naming collision into a 422 that names the offending
     * dashboard. Left to the index it surfaces as a failed insert — a 500 carrying
     * the constraint name and the statement, for someone who simply typed a name
     * they had used before.
     *
     * @throws NameAlreadyUsed
     */
    private function assertNameIsFree(string $workspaceId, string $actorId, string $name, ?string $exceptId): void
    {
        if ($this->dashboards->nameTaken($workspaceId, $actorId, $name, $exceptId)) {
            throw NameAlreadyUsed::byADashboard($name);
        }
    }

    /**
     * @param  list<WidgetInput>|null  $widgets
     *
     * @throws InvalidWidget|InvalidPlacement
     */
    private function assertWidgetsAreCoherent(?array $widgets): void
    {
        if ($widgets === null) {
            return;
        }

        foreach ($widgets as $widget) {
            if ($widget->metrics === []) {
                throw InvalidWidget::needsAMetric($widget->type->label());
            }

            if (! $widget->type->supports($widget->dimension)) {
                throw InvalidWidget::cannotShowDimension(
                    $widget->type->label(),
                    $widget->dimension?->label(),
                );
            }

            if ($widget->type->requiresSecondMetric() && count($widget->metrics) < 2) {
                // A scatter plots one metric against another; with one it has no
                // second axis and would collapse onto a line.
                throw InvalidWidget::needsTwoMetrics($widget->type->label());
            }
        }

        $this->assertNoOverlap($widgets);
    }

    /**
     * Two widgets cannot occupy the same cells.
     *
     * An overlap does not error in the browser — one widget simply covers the other,
     * and the hidden one is invisible until someone moves it. Refusing the save is
     * the only way the reader finds out.
     *
     * @param  list<WidgetInput>  $widgets
     */
    private function assertNoOverlap(array $widgets): void
    {
        /** @var list<array{placement: GridPlacement, title: string}> $seen */
        $seen = [];

        foreach ($widgets as $widget) {
            foreach ($seen as $other) {
                if ($widget->placement->overlaps($other['placement'])) {
                    throw InvalidWidget::overlaps(
                        $widget->title ?? $widget->type->label(),
                        $other['title'],
                    );
                }
            }

            $seen[] = [
                'placement' => $widget->placement,
                'title' => $widget->title ?? $widget->type->label(),
            ];
        }
    }

    private function assertProjectScope(DashboardInput $input): void
    {
        if ($input->visibility !== Visibility::Project) {
            return;
        }

        if ($input->projectId === null) {
            throw ResourceNotFound::of('Project');
        }

        $visible = $this->visibility->visibleProjectIds();

        if ($visible !== null && ! in_array($input->projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }
    }
}
