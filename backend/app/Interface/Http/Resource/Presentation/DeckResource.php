<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Presentation;

use App\Application\Presentation\DTO\Deck;
use App\Application\Presentation\DTO\Slide;
use App\Application\Presentation\DTO\SlideFigure;
use App\Application\Presentation\DTO\SlideSeries;
use App\Domain\Presentation\DeckKind;
use App\Domain\Presentation\DeferredDeck;
use App\Domain\Presentation\PresentationTheme;
use App\Domain\Presentation\SlideKind;

/**
 * A deck as JSON, so the browser can render the same slides it would export.
 *
 * **The same object feeds both renderers.** The page a reader previews and prints, and the PPTX file they
 * download, are built from this — so a deck somebody sends is the deck they saw. A preview assembled separately
 * would eventually disagree with the file, and the disagreement would surface in front of a client.
 *
 * The theme travels with it for the same reason: the page draws from the tokens rather than from a stylesheet of
 * its own, so swapping the theme changes both at once.
 */
final class DeckResource
{
    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'kinds' => array_map(
                static fn (DeckKind $kind): array => [
                    'kind' => $kind->value,
                    'label' => $kind->label(),
                    'description' => $kind->description(),
                    'window' => $kind->window()?->value,
                    'compares_periods' => $kind->comparesPeriods(),
                    'built_from_budget' => $kind->isBuiltFromBudget(),
                ],
                DeckKind::cases(),
            ),
            /*
             * Published rather than omitted, and rather than offered as a template that produces empty slides.
             * "Not built" and "deliberately not from this data" are different answers.
             */
            'deferred' => array_map(
                static fn (DeferredDeck $deck): array => [
                    'kind' => $deck->value,
                    'label' => $deck->label(),
                    'because' => $deck->because(),
                ],
                DeferredDeck::cases(),
            ),
            'slide_kinds' => array_map(
                static fn (SlideKind $kind): array => [
                    'kind' => $kind->value,
                    'label' => $kind->label(),
                    'draws_chart' => $kind->drawsChart(),
                ],
                SlideKind::cases(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function deck(Deck $deck): array
    {
        return [
            'kind' => $deck->kind->value,
            'kind_label' => $deck->kind->label(),
            'client_name' => $deck->clientName,
            'title' => $deck->title,
            'subtitle' => $deck->subtitle,
            'source' => $deck->source->value,
            'source_label' => $deck->source->label(),
            'requires_label' => $deck->source->requiresLabel(),
            'prepared_at' => $deck->preparedAt->format(DATE_ATOM),
            'is_client_safe' => $deck->isClientSafe,
            'theme' => self::theme($deck->theme),
            'slides' => array_map(static fn (Slide $slide): array => self::slide($slide), $deck->slides),
        ];
    }

    /** @return array<string, mixed> */
    private static function theme(PresentationTheme $theme): array
    {
        return [
            'name' => $theme->name,
            /*
             * Published, so the interface can say so. Smart Code's identity has not been provided to this
             * repository, and a placeholder presented as a brand is worse than an obvious placeholder.
             */
            'is_provisional' => $theme->isProvisional,
            'slide_width_pt' => $theme->slideWidthPt,
            'slide_height_pt' => $theme->slideHeightPt,
            'canvas' => $theme->canvas,
            'ink' => $theme->ink,
            'ink_muted' => $theme->inkMuted,
            'accent' => $theme->accent,
            'accent_contrast' => $theme->accentContrast,
            'line' => $theme->line,
            'latin_font' => $theme->latinFont,
            'arabic_font' => $theme->arabicFont,
            'title_size' => $theme->titleSize,
            'heading_size' => $theme->headingSize,
            'body_size' => $theme->bodySize,
            'caption_size' => $theme->captionSize,
            'series' => $theme->series,
        ];
    }

    /** @return array<string, mixed> */
    private static function slide(Slide $slide): array
    {
        return [
            'kind' => $slide->kind->value,
            'title' => $slide->title,
            'lead' => $slide->lead,
            'figures' => array_map(
                static fn (SlideFigure $figure): array => [
                    'label' => $figure->label,
                    /* Already formatted. No renderer formats a number — see the note on `Slide`. */
                    'value' => $figure->value,
                    'change' => $figure->change,
                    'change_is_good' => $figure->changeIsGood,
                    'source_label' => $figure->sourceLabel,
                    'unavailable_reason' => $figure->unavailableReason,
                ],
                $slide->figures,
            ),
            'series' => array_map(
                static fn (SlideSeries $series): array => [
                    'name' => $series->name,
                    /* Nulls stay null: a day with no delivery is a gap, not a zero. */
                    'points' => $series->points,
                    'labels' => $series->labels,
                ],
                $slide->series,
            ),
            'categories' => $slide->categories,
            'table' => $slide->table === null ? null : [
                'columns' => $slide->table->columns,
                'rows' => $slide->table->rows,
            ],
            'notes' => $slide->notes,
            'paragraphs' => $slide->paragraphs,
        ];
    }
}
