<?php

declare(strict_types=1);

namespace App\Infrastructure\Presentation;

use App\Application\Presentation\Contract\DeckWriter;
use App\Application\Presentation\DTO\Deck;
use App\Application\Presentation\DTO\Slide;
use App\Application\Presentation\DTO\SlideFigure;
use App\Application\Presentation\DTO\SlideSeries;
use App\Application\Presentation\DTO\SlideTable;
use App\Domain\Presentation\PresentationTheme;
use App\Domain\Presentation\SlideKind;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide as PptxSlide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Writer\PowerPoint2007;
use RuntimeException;

/**
 * A deck, as an editable PowerPoint file.
 *
 * **Editable is the requirement, which is why this is a real PPTX and not a PDF of pictures.** An agency changes a
 * title, drops a slide, adds one of their own; a deck they cannot open is a deck they re-make by hand. So the
 * figures are text runs, the tables are tables, and the charts are native PPTX charts with their own data — a
 * reader can click a bar and see the number behind it, and the deck survives being re-themed.
 *
 * Every dimension and every colour comes from `PresentationTheme`. Nothing in this class knows a hex value, which
 * is the point of that object existing: Smart Code's identity has not been provided to this repository, and a
 * guess baked in here would be a rewrite rather than a configuration change when the reference deck arrives.
 *
 * **RTL is set per paragraph, not per deck.** A slide can legitimately carry an Arabic headline over a Latin
 * platform name, and `Alignment::setIsRTL()` on the paragraph is what makes each read correctly. The face is
 * chosen by script for the same reason: Arabic set in a Latin font is Arabic that has been let down by whoever
 * made the deck.
 *
 * PDF is deliberately **not** written here. See `docs/PRESENTATIONS.md`: this library's PDF path goes through
 * HTML and DomPDF, whose Arabic shaping is not the browser's, and a second rendering engine is a second thing to
 * disagree with the screen.
 */
final readonly class PptxWriter implements DeckWriter
{
    /** Slide geometry, in points, as proportions of the theme's own page. */
    private const float MARGIN = 48.0;

    private const float TITLE_TOP = 56.0;

    private const float BODY_TOP = 150.0;

    public function extension(): string
    {
        return 'pptx';
    }

    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }

    public function write(Deck $deck): string
    {
        $presentation = new PhpPresentation;
        $theme = $deck->theme;

        $presentation->getLayout()->setCX((int) $theme->slideWidthPt, 'pt');
        $presentation->getLayout()->setCY((int) $theme->slideHeightPt, 'pt');

        $presentation->getDocumentProperties()
            ->setCreator('digitalSmart')
            ->setTitle($deck->title)
            ->setSubject($deck->subtitle)
            /*
             * The source state, in the file's own properties. A slide is photographed and forwarded; the file
             * keeps the qualification even when the image loses it.
             */
            ->setDescription(sprintf('Figures: %s. Prepared %s.', $deck->source->label(), $deck->preparedAt->format('c')));

        $first = true;

        foreach ($deck->slides as $slide) {
            $target = $first ? $presentation->getActiveSlide() : $presentation->createSlide();
            $first = false;

            $this->draw($target, $slide, $theme);
        }

        $path = tempnam(sys_get_temp_dir(), 'deck');

        if ($path === false) {
            throw new RuntimeException('Could not open a temporary file to write the deck into.');
        }

        (new PowerPoint2007($presentation))->save($path);

        return $path;
    }

    /**
     * A theme size in whole points.
     *
     * The theme carries fractional sizes because a stylesheet can use them; PowerPoint's own font size is whole
     * points. Rounding here, at the boundary, is the adapter doing its job — the alternative is a theme that
     * cannot express 10.5pt anywhere because one of its two renderers cannot.
     */
    private function points(float $size): int
    {
        return (int) round($size);
    }

    private function draw(PptxSlide $target, Slide $slide, PresentationTheme $theme): void
    {
        $this->heading($target, $slide, $theme);

        match ($slide->kind) {
            SlideKind::Title => null,
            SlideKind::Scorecard => $this->figures($target, $slide->figures, $theme),
            SlideKind::Trend => $this->chart($target, $slide, $theme, isBar: false),
            SlideKind::Breakdown => $this->chart($target, $slide, $theme, isBar: true),
            SlideKind::Table => $slide->table === null
                ? null
                : $this->table($target, $slide->table, $theme),
            SlideKind::Caveat, SlideKind::Commentary => $this->paragraphs($target, $slide->paragraphs, $theme),
        };

        $this->notes($target, $slide, $theme);
    }

    private function heading(PptxSlide $target, Slide $slide, PresentationTheme $theme): void
    {
        $isTitleSlide = $slide->kind === SlideKind::Title;

        $box = $this->text($target, $theme, self::MARGIN, self::TITLE_TOP, $theme->slideWidthPt - self::MARGIN * 2, 80);
        $paragraph = $box->createParagraph();
        $paragraph->getAlignment()->setIsRTL($this->isArabic($slide->title));
        $run = $paragraph->createTextRun($slide->title);
        $run->getFont()
            ->setName($theme->fontFor($this->isArabic($slide->title)))
            ->setSize($this->points($isTitleSlide ? $theme->titleSize : $theme->headingSize))
            ->setBold(true)
            ->setColor(new Color('FF'.$theme->ink));

        if ($slide->lead === null) {
            return;
        }

        $leadBox = $this->text(
            $target,
            $theme,
            self::MARGIN,
            self::TITLE_TOP + ($isTitleSlide ? 72 : 44),
            $theme->slideWidthPt - self::MARGIN * 2,
            48,
        );
        $leadParagraph = $leadBox->createParagraph();
        $leadParagraph->getAlignment()->setIsRTL($this->isArabic($slide->lead));
        $leadRun = $leadParagraph->createTextRun($slide->lead);
        $leadRun->getFont()
            ->setName($theme->fontFor($this->isArabic($slide->lead)))
            ->setSize($this->points($theme->bodySize))
            ->setColor(new Color('FF'.$theme->inkMuted));
    }

    /**
     * The headline figures, as a row of text blocks.
     *
     * Deliberately not a table. A scorecard is the slide somebody photographs, and a table's gridlines turn six
     * figures into a spreadsheet nobody reads from the back of a room.
     *
     * @param  list<SlideFigure>  $figures
     */
    private function figures(PptxSlide $target, array $figures, PresentationTheme $theme): void
    {
        if ($figures === []) {
            return;
        }

        $perRow = count($figures) > 3 ? 3 : count($figures);
        $available = $theme->slideWidthPt - self::MARGIN * 2;
        $width = $available / $perRow;

        foreach ($figures as $index => $figure) {
            $column = $index % $perRow;
            $row = intdiv($index, $perRow);

            $box = $this->text(
                $target,
                $theme,
                self::MARGIN + $column * $width,
                self::BODY_TOP + $row * 120,
                $width - 12,
                110,
            );

            $label = $box->createParagraph();
            $label->createTextRun($figure->label)->getFont()
                ->setName($theme->latinFont)
                ->setSize($this->points($theme->captionSize))
                ->setColor(new Color('FF'.$theme->inkMuted));

            $value = $box->createParagraph();
            $value->createTextRun($figure->value)->getFont()
                ->setName($theme->latinFont)
                ->setSize($this->points($theme->headingSize))
                ->setBold(true)
                ->setColor(new Color('FF'.$theme->ink));

            $footnote = $box->createParagraph();
            $footnote->createTextRun($this->figureFootnote($figure))->getFont()
                ->setName($theme->latinFont)
                ->setSize($this->points($theme->captionSize))
                ->setColor(new Color('FF'.$theme->inkMuted));
        }
    }

    /**
     * What goes under a figure.
     *
     * The change when there is one, the reason when the figure is absent, and the source state always. A figure
     * with no source on a slide is a figure that will be quoted without one.
     */
    private function figureFootnote(SlideFigure $figure): string
    {
        if ($figure->unavailableReason !== null) {
            return $figure->unavailableReason;
        }

        return $figure->change === null
            ? $figure->sourceLabel
            : sprintf('%s · %s', $figure->change, $figure->sourceLabel);
    }

    private function chart(PptxSlide $target, Slide $slide, PresentationTheme $theme, bool $isBar): void
    {
        if ($slide->series === [] || $slide->categories === []) {
            return;
        }

        $shape = $target->createChartShape();
        $shape->setOffsetX((int) self::MARGIN)
            ->setOffsetY((int) self::BODY_TOP)
            ->setWidth((int) ($theme->slideWidthPt - self::MARGIN * 2))
            ->setHeight((int) ($theme->slideHeightPt - self::BODY_TOP - 110));

        $shape->getTitle()->setVisible(false);
        /*
         * A gap, not a zero. A day with no delivery drawn as zero is a line through the floor of a chart
         * somebody is presenting to a client.
         */
        $shape->setDisplayBlankAs(\PhpOffice\PhpPresentation\Shape\Chart::BLANKAS_GAP);

        $type = $isBar ? new Bar : new Line;

        if ($type instanceof Bar) {
            /* Horizontal, so a platform name has room to be read. */
            $type->setBarDirection(Bar::DIRECTION_HORIZONTAL);
        }

        foreach ($slide->series as $index => $series) {
            $type->addSeries($this->series($series, $slide->categories, $theme, $index));
        }

        $shape->getPlotArea()->setType($type);
        $shape->getLegend()->setVisible(count($slide->series) > 1);
    }

    /** @param  list<string>  $categories */
    private function series(SlideSeries $series, array $categories, PresentationTheme $theme, int $index): Series
    {
        $values = [];

        foreach ($categories as $position => $category) {
            $point = $series->points[$position] ?? null;

            /*
             * Written as strings, because that is what the library puts into the chart's embedded worksheet —
             * and null stays null, so a day with no delivery is a gap rather than a line through the floor.
             */
            $values[$category] = $point === null ? null : (string) $point;
        }

        $drawn = new Series($series->name, $values);

        $fill = new Fill;
        $fill->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('FF'.$theme->seriesColour($index)))
            ->setEndColor(new Color('FF'.$theme->seriesColour($index)));
        $drawn->setFill($fill);

        $font = $drawn->getFont();

        if ($font !== null) {
            $font->setName($theme->latinFont)->setSize($this->points($theme->captionSize));
        }

        return $drawn;
    }

    private function table(PptxSlide $target, SlideTable $table, PresentationTheme $theme): void
    {
        $columns = count($table->columns);

        if ($columns === 0) {
            return;
        }

        $shape = $target->createTableShape($columns);
        $shape->setOffsetX((int) self::MARGIN)
            ->setOffsetY((int) self::BODY_TOP)
            ->setWidth((int) ($theme->slideWidthPt - self::MARGIN * 2));

        $header = $shape->createRow();
        $header->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('FF'.$theme->accent))
            ->setEndColor(new Color('FF'.$theme->accent));

        foreach ($table->columns as $position => $column) {
            $cell = $header->getCell($position);
            $run = $cell->createTextRun($column);
            $run->getFont()
                ->setName($theme->latinFont)
                ->setSize($this->points($theme->captionSize))
                ->setBold(true)
                ->setColor(new Color('FF'.$theme->accentContrast));
        }

        foreach ($table->rows as $row) {
            $drawn = $shape->createRow();
            $cells = [$row['label'], ...$row['cells']];

            foreach ($cells as $position => $value) {
                if ($position >= $columns) {
                    break;
                }

                $cell = $drawn->getCell($position);
                $paragraph = $cell->createParagraph();
                $paragraph->getAlignment()->setIsRTL($this->isArabic($value));
                $run = $paragraph->createTextRun($value);
                $run->getFont()
                    ->setName($theme->fontFor($this->isArabic($value)))
                    ->setSize($this->points($theme->captionSize))
                    ->setColor(new Color('FF'.$theme->ink));
            }
        }
    }

    /** @param  list<string>  $paragraphs */
    private function paragraphs(PptxSlide $target, array $paragraphs, PresentationTheme $theme): void
    {
        if ($paragraphs === []) {
            return;
        }

        $box = $this->text(
            $target,
            $theme,
            self::MARGIN,
            self::BODY_TOP,
            $theme->slideWidthPt - self::MARGIN * 2,
            $theme->slideHeightPt - self::BODY_TOP - 90,
        );

        foreach ($paragraphs as $text) {
            $paragraph = $box->createParagraph();
            $paragraph->getAlignment()->setIsRTL($this->isArabic($text));
            $paragraph->createTextRun($text)->getFont()
                ->setName($theme->fontFor($this->isArabic($text)))
                ->setSize($this->points($theme->bodySize))
                ->setColor(new Color('FF'.$theme->ink));
        }
    }

    private function notes(PptxSlide $target, Slide $slide, PresentationTheme $theme): void
    {
        $notes = $slide->notes;

        if ($slide->kind === SlideKind::Title && $theme->isProvisional) {
            /*
             * Said on the deck, not in a README. The theme is a placeholder until a reference deck exists, and a
             * placeholder presented as an agency's brand is worse than an obvious one.
             */
            $notes[] = 'Theme: '.$theme->name.'. Awaiting the brand reference deck.';
        }

        if ($notes === []) {
            return;
        }

        $box = $this->text(
            $target,
            $theme,
            self::MARGIN,
            $theme->slideHeightPt - 72,
            $theme->slideWidthPt - self::MARGIN * 2,
            56,
        );

        foreach ($notes as $note) {
            $paragraph = $box->createParagraph();
            $paragraph->getAlignment()->setIsRTL($this->isArabic($note));
            $paragraph->createTextRun($note)->getFont()
                ->setName($theme->fontFor($this->isArabic($note)))
                ->setSize($this->points($theme->captionSize))
                ->setColor(new Color('FF'.$theme->inkMuted));
        }
    }

    private function text(
        PptxSlide $target,
        PresentationTheme $theme,
        float $x,
        float $y,
        float $width,
        float $height,
    ): RichText {
        $box = $target->createRichTextShape();
        $box->setOffsetX((int) $x)
            ->setOffsetY((int) $y)
            ->setWidth((int) $width)
            ->setHeight((int) $height);
        $box->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        return $box;
    }

    /**
     * Whether a string is Arabic.
     *
     * One range check, applied per string rather than per deck. A slide legitimately carries an Arabic headline
     * over a Latin platform name, and a deck-wide flag would set one of them in the wrong face.
     */
    private function isArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }
}
