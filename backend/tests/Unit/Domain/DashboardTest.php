<?php

declare(strict_types=1);

use App\Domain\Analytics\Dimension;
use App\Domain\Dashboard\Exception\InvalidPlacement;
use App\Domain\Dashboard\GridPlacement;
use App\Domain\Dashboard\Visibility;
use App\Domain\Dashboard\WidgetType;

/*
|--------------------------------------------------------------------------
| Dashboard domain
|--------------------------------------------------------------------------
|
| The rules that stop a dashboard from quietly losing a widget or drawing a chart
| that cannot answer the question it was given.
|
*/

describe('grid placement', function (): void {
    it('refuses a widget that would run off the grid', function (): void {
        /*
         * Raised, not clamped. Clamping moves a widget the reader deliberately
         * placed; storing it off-grid removes it from the dashboard with nothing to
         * say it was ever there.
         */
        expect(fn (): GridPlacement => new GridPlacement(column: 8, row: 0, width: 6, height: 2))
            ->toThrow(InvalidPlacement::class);
    });

    it('refuses a widget too narrow to render anything', function (): void {
        expect(fn (): GridPlacement => new GridPlacement(column: 0, row: 0, width: 1, height: 2))
            ->toThrow(InvalidPlacement::class);
    });

    it('refuses a negative row', function (): void {
        expect(fn (): GridPlacement => new GridPlacement(column: 0, row: -1, width: 6, height: 2))
            ->toThrow(InvalidPlacement::class);
    });

    it('accepts a full-width widget', function (): void {
        $placement = new GridPlacement(column: 0, row: 3, width: GridPlacement::COLUMNS, height: 2);

        expect($placement->endColumn())->toBe(GridPlacement::COLUMNS);
    });

    it('detects an overlap only within the same row', function (): void {
        $left = new GridPlacement(column: 0, row: 0, width: 6, height: 2);
        $overlapping = new GridPlacement(column: 4, row: 0, width: 6, height: 2);
        $beside = new GridPlacement(column: 6, row: 0, width: 6, height: 2);
        $below = new GridPlacement(column: 4, row: 1, width: 6, height: 2);

        expect($left->overlaps($overlapping))->toBeTrue()
            // Adjacent, not overlapping: column 6 begins exactly where the first ends.
            ->and($left->overlaps($beside))->toBeFalse()
            ->and($left->overlaps($below))->toBeFalse();
    });
});

describe('widget types', function (): void {
    it('knows which types need a dimension to mean anything', function (): void {
        expect(WidgetType::Scorecard->requiresDimension())->toBeFalse()
            ->and(WidgetType::Line->requiresDimension())->toBeFalse()
            ->and(WidgetType::Bar->requiresDimension())->toBeTrue()
            ->and(WidgetType::Share->requiresDimension())->toBeTrue();
    });

    it('refuses a share-of-total over dates', function (): void {
        /*
         * The clearest case of a chart that draws without meaning: ninety slices,
         * each one a day, summing to a number nobody asked for.
         */
        expect(WidgetType::Share->supports(Dimension::Date))->toBeFalse()
            ->and(WidgetType::Share->supports(Dimension::Campaign))->toBeTrue();
    });

    it('refuses a scatter over dates, which has no second entity axis', function (): void {
        expect(WidgetType::Scatter->supports(Dimension::Date))->toBeFalse()
            ->and(WidgetType::Scatter->requiresSecondMetric())->toBeTrue();
    });

    it('lets a scorecard stand alone or follow a date', function (): void {
        expect(WidgetType::Scorecard->supports(null))->toBeTrue()
            ->and(WidgetType::Scorecard->supports(Dimension::Date))->toBeTrue()
            // A scorecard per campaign is a table, not a scorecard.
            ->and(WidgetType::Scorecard->supports(Dimension::Campaign))->toBeFalse();
    });

    it('treats a table as able to show any grouping', function (): void {
        foreach (Dimension::cases() as $dimension) {
            expect(WidgetType::Table->supports($dimension))->toBeTrue();
        }
    });
});

describe('visibility', function (): void {
    it('requires a project only for project scope', function (): void {
        expect(Visibility::Project->requiresProject())->toBeTrue()
            ->and(Visibility::Workspace->requiresProject())->toBeFalse()
            ->and(Visibility::Private->requiresProject())->toBeFalse();
    });

    it('knows which states reach anyone but the owner', function (): void {
        expect(Visibility::Private->isShared())->toBeFalse()
            ->and(Visibility::Workspace->isShared())->toBeTrue()
            // The case that matters for an agency: visible to one client's team and
            // not to colleagues working for a different client.
            ->and(Visibility::Project->isShared())->toBeTrue();
    });
});
