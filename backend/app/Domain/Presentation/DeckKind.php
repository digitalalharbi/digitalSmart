<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Reporting\ReportWindow;

/**
 * A deck somebody actually asks for.
 *
 * Four, each with a period that is part of what it *means*: a weekly report over ninety days is not a weekly
 * report. The window is therefore the kind's own rather than a parameter, which is also what stops the same deck
 * being generated three times with three different periods and presented as three different documents.
 *
 * What is deliberately absent is in {@see DeferredDeck}. A campaign proposal is a document an agency **writes** —
 * the objective, the audience, the creative idea — and composing one from last month's delivery would produce a
 * proposal shaped entirely by what was easiest to measure. The engine builds it the day the inputs exist; it does
 * not pretend they do.
 */
enum DeckKind: string
{
    case WeeklyReport = 'weekly_report';

    case MonthlyReport = 'monthly_report';

    /** The end of an engagement or a flight. No comparison: there is nothing after it to compare with. */
    case ClosingReport = 'closing_report';

    /** Forward-looking, and composed from a budget somebody has already allocated. */
    case MediaPlan = 'media_plan';

    public function label(): string
    {
        return match ($this) {
            self::WeeklyReport => 'Weekly report',
            self::MonthlyReport => 'Monthly report',
            self::ClosingReport => 'Closing report',
            self::MediaPlan => 'Media plan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WeeklyReport => 'The last seven days, against the seven before. For a standing call.',
            self::MonthlyReport => 'Last calendar month, against the month before. The document a client files.',
            self::ClosingReport => 'The last ninety days, with no comparison — there is nothing after an '
                .'engagement to compare it with.',
            self::MediaPlan => 'What a budget allocates, by platform, and what has been spent against it so far.',
        };
    }

    /**
     * The period this deck covers.
     *
     * Part of what the deck means rather than a parameter. A "weekly report" over ninety days is a different
     * document wearing the same name, and a reader who received both in the same month could not tell them apart
     * from the title.
     *
     * Null for the media plan, whose period is the budget's own.
     */
    public function window(): ?ReportWindow
    {
        return match ($this) {
            self::WeeklyReport => ReportWindow::Last7Days,
            self::MonthlyReport => ReportWindow::LastMonth,
            self::ClosingReport => ReportWindow::Last90Days,
            self::MediaPlan => null,
        };
    }

    /**
     * Whether this deck compares against an earlier period.
     *
     * False for the closing report, and that is the honest choice: a closing report compared against the
     * ninety days before it would invite a reader to treat the end of an engagement as a trend.
     */
    public function comparesPeriods(): bool
    {
        return $this === self::WeeklyReport || $this === self::MonthlyReport;
    }

    /** What this deck is built from: a client's performance, or a budget. */
    public function isBuiltFromBudget(): bool
    {
        return $this === self::MediaPlan;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
