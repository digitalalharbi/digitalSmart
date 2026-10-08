<?php

declare(strict_types=1);

use App\Domain\Presentation\DeckKind;
use App\Domain\Presentation\DeferredDeck;
use App\Domain\Presentation\PresentationTheme;
use App\Domain\Presentation\SlideKind;
use App\Domain\Reporting\ReportWindow;

/*
|--------------------------------------------------------------------------
| The presentation vocabulary
|--------------------------------------------------------------------------
|
| The theme comes first in these tests because it came first in the module. Smart Code's own presentation identity
| has not been provided to this repository, and the brief is explicit: build the token abstraction before anything
| is drawn. A guess baked into a renderer would be a rewrite rather than a configuration change.
|
*/

describe('the theme', function (): void {
    it('says it is a placeholder', function (): void {
        $theme = PresentationTheme::provisional();

        /* A placeholder presented as an agency's brand is worse than an obvious placeholder. */
        expect($theme->isProvisional)->toBeTrue();
        expect($theme->name)->toContain('provisional');
    });

    it('carries a face for each script', function (): void {
        $theme = PresentationTheme::provisional();

        /*
         * Two fonts is a requirement rather than a nicety. Arabic set in a Latin face is Arabic that has been
         * let down by whoever made the deck.
         */
        expect($theme->fontFor(isArabic: true))->toBe('IBM Plex Sans Arabic');
        expect($theme->fontFor(isArabic: false))->toBe('Inter');
    });

    it('opens at the size PowerPoint opens', function (): void {
        $theme = PresentationTheme::provisional();

        /* 13.333in × 7.5in at 72pt to the inch: 16:9, and what a new deck is. */
        expect($theme->slideWidthPt)->toBe(960.0);
        expect($theme->slideHeightPt)->toBe(540.0);
    });

    it('names its colours by role rather than by hue', function (): void {
        $theme = PresentationTheme::provisional();

        /*
         * A token called "blue" cannot be rebranded. These are the tokens a reference deck will overwrite, and
         * every one of them is a role.
         */
        foreach ([$theme->canvas, $theme->ink, $theme->inkMuted, $theme->accent, $theme->line] as $colour) {
            expect($colour)->toMatch('/^[0-9A-F]{6}$/');
        }
    });

    it('assigns a series colour by position and wraps rather than inventing one', function (): void {
        $theme = PresentationTheme::provisional(['AAAAAA', 'BBBBBB']);

        expect($theme->seriesColour(0))->toBe('AAAAAA');
        expect($theme->seriesColour(1))->toBe('BBBBBB');
        /* A ninth series is never a generated hue — see docs/DATA_VISUALISATION.md. */
        expect($theme->seriesColour(2))->toBe('AAAAAA');
    });

    it('falls back to the accent rather than failing on an empty ramp', function (): void {
        $theme = PresentationTheme::provisional([]);

        expect($theme->seriesColour(0))->toBe($theme->accent);
    });
});

describe('deck kinds', function (): void {
    it('makes the period part of what each deck means', function (): void {
        /* A "weekly report" over ninety days is a different document wearing the same name. */
        expect(DeckKind::WeeklyReport->window())->toBe(ReportWindow::Last7Days);
        expect(DeckKind::MonthlyReport->window())->toBe(ReportWindow::LastMonth);
        expect(DeckKind::ClosingReport->window())->toBe(ReportWindow::Last90Days);

        /* The media plan's period is the budget's own. */
        expect(DeckKind::MediaPlan->window())->toBeNull();
    });

    it('does not compare a closing report with anything', function (): void {
        /* There is nothing after an engagement to compare it with, and inviting the comparison implies a trend. */
        expect(DeckKind::ClosingReport->comparesPeriods())->toBeFalse();
        expect(DeckKind::WeeklyReport->comparesPeriods())->toBeTrue();
        expect(DeckKind::MonthlyReport->comparesPeriods())->toBeTrue();
    });

    it('knows which deck is built from a budget rather than from delivery', function (): void {
        expect(DeckKind::MediaPlan->isBuiltFromBudget())->toBeTrue();

        foreach ([DeckKind::WeeklyReport, DeckKind::MonthlyReport, DeckKind::ClosingReport] as $kind) {
            expect($kind->isBuiltFromBudget())->toBeFalse();
        }
    });

    it('describes every deck somebody might ask for', function (): void {
        foreach (DeckKind::cases() as $kind) {
            expect($kind->label())->not->toBe('');
            expect($kind->description())->not->toBe('');
        }
    });
});

describe('the deck it will not compose', function (): void {
    it('gives its reason', function (): void {
        /*
         * Published rather than omitted, and rather than offered as a template that produces empty slides. "Not
         * built" and "deliberately not from this data" are different answers.
         */
        expect(DeferredDeck::values())->toBe(['campaign_proposal']);
        expect(DeferredDeck::CampaignProposal->because())->toContain('what is missing is the brief');
    });

    it('does not overlap with the decks it does compose', function (): void {
        expect(array_intersect(DeferredDeck::values(), DeckKind::values()))->toBe([]);
    });
});

describe('slide kinds', function (): void {
    it('makes the caveats a slide', function (): void {
        /*
         * A deck is read in a room, out loud, by somebody who may not have built it. A footnote in six-point
         * type is a footnote the projector crops.
         */
        expect(SlideKind::Caveat->label())->toBe('What these figures do not say');
        expect(SlideKind::values())->toContain('caveat');
    });

    it('says which slides draw a chart', function (): void {
        expect(SlideKind::Trend->drawsChart())->toBeTrue();
        expect(SlideKind::Breakdown->drawsChart())->toBeTrue();

        /* A scorecard is a row of figures. Sometimes the right answer is not a chart. */
        expect(SlideKind::Scorecard->drawsChart())->toBeFalse();
        expect(SlideKind::Table->drawsChart())->toBeFalse();
    });
});
