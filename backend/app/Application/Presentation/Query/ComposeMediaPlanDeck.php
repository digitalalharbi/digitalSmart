<?php

declare(strict_types=1);

namespace App\Application\Presentation\Query;

use App\Application\Budget\DTO\AllocationPacingView;
use App\Application\Budget\DTO\AllocationView;
use App\Application\Budget\Query\GetBudgetPacing;
use App\Application\Presentation\DTO\Deck;
use App\Application\Presentation\DTO\Slide;
use App\Application\Presentation\DTO\SlideFigure;
use App\Application\Presentation\DTO\SlideSeries;
use App\Application\Presentation\DTO\SlideTable;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Service\MetricText;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use App\Domain\Presentation\DeckKind;
use App\Domain\Presentation\PresentationTheme;
use App\Domain\Presentation\SlideKind;

/**
 * A media plan deck, from a budget somebody has already allocated.
 *
 * **It presents a plan the agency made, not a plan this product invented.** The allocation is somebody's decision,
 * recorded in the budgets module; this lays it out. A generator that drafted an allocation from last month's
 * delivery would be proposing a plan shaped entirely by what was easiest to measure — which is the same reason the
 * assistant's `Plan` mode is deferred.
 *
 * Two things it refuses to do.
 *
 * **It does not present an unapproved budget as a plan.** A draft allocation is a working document, and a deck
 * with a client's logo on it carrying somebody's half-finished arithmetic is how a number nobody agreed gets
 * quoted back. An unapproved budget still produces a deck — the agency may legitimately be presenting a proposal —
 * but the status is on the title slide, in words.
 *
 * **It does not net spend against allocation silently.** The plan and what has been spent against it are two
 * figures side by side, because "80% spent" is a different fact from "80% spent with three weeks left", and the
 * pacing module is the one that knows the difference.
 */
final readonly class ComposeMediaPlanDeck
{
    public function __construct(
        private GetBudgetPacing $pacing,
        private MetricText $text,
        private AuthorizationGuard $guard,
        private Clock $clock,
    ) {}

    public function __invoke(string $budgetId): Deck
    {
        /* The budgets module's own permission, applied again by `GetBudgetPacing`. */
        $this->guard->ensure(Permission::BudgetsView);

        $view = ($this->pacing)($budgetId);
        $budget = $view->budget;
        $now = $this->clock->now();

        $allocated = $budget->allocatedMinor();
        $unallocated = $budget->unallocatedMinor();
        $spent = $view->spend->minorUnits;

        $slides = [
            new Slide(
                kind: SlideKind::Title,
                title: sprintf('%s — %s', $budget->clientName, $budget->name),
                lead: sprintf(
                    '%s to %s',
                    $budget->periodStart->format('Y-m-d'),
                    $budget->periodEnd->format('Y-m-d'),
                ),
                notes: [
                    /* On the slide, in words. A draft allocation presented as a plan is a number nobody agreed. */
                    sprintf('Budget status: %s.', $budget->status->label()),
                    sprintf('Prepared %s.', $now->format('j F Y')),
                ],
            ),
            new Slide(
                kind: SlideKind::Scorecard,
                title: 'The plan',
                lead: null,
                figures: [
                    $this->figure('Budget', $budget->amountMinor, $budget->currency),
                    $this->figure('Allocated', $allocated, $budget->currency),
                    /*
                     * Its own figure rather than a subtraction left to the reader. An unallocated remainder is
                     * either deliberate or an oversight, and a deck that hid it makes both look the same.
                     */
                    $this->figure('Not yet allocated', $unallocated, $budget->currency),
                    $spent === null
                        ? new SlideFigure(
                            label: 'Spent so far',
                            value: '—',
                            change: null,
                            changeIsGood: null,
                            sourceLabel: $view->spend->source->label(),
                            unavailableReason: $view->spend->unavailableReason
                                ?? 'No spend has been read for this period.',
                        )
                        : $this->figure('Spent so far', $spent, $budget->currency, $view->spend->source),
                ],
                notes: [
                    sprintf('Plan figures are the agency\'s own. Spend: %s.', $view->spend->source->label()),
                ],
            ),
        ];

        $byPlatform = $this->byPlatform($budget->allocations, $view->allocations, $budget->currency);

        if ($byPlatform !== null) {
            $slides[] = $byPlatform;
        }

        $slides[] = new Slide(
            kind: SlideKind::Caveat,
            title: SlideKind::Caveat->label(),
            lead: 'Read out, not skipped.',
            paragraphs: array_values(array_filter([
                'The allocation is a decision somebody made, not a figure this product calculated.',
                $view->unavailableReason === null
                    ? 'Pacing compares spend against the period elapsed, and says nothing about whether the '
                        .'spend was well placed.'
                    : $view->unavailableReason,
                $view->spend->source->requiresLabel()
                    ? sprintf('Spend is %s. Read it as that.', $view->spend->source->label())
                    : null,
            ])),
        );

        return new Deck(
            kind: DeckKind::MediaPlan,
            theme: PresentationTheme::provisional(),
            clientName: $budget->clientName,
            title: sprintf('%s — %s', $budget->clientName, DeckKind::MediaPlan->label()),
            subtitle: $budget->name,
            slides: $slides,
            source: $view->spend->source,
            preparedAt: $now,
            /*
             * A media plan is written to be presented to the client whose money it allocates, and it carries no
             * internal note: `BudgetView::$notes` is deliberately not read here.
             */
            isClientSafe: true,
        );
    }

    private function figure(
        string $label,
        int $minor,
        string $currency,
        ?MetricSource $source = null,
    ): SlideFigure {
        return new SlideFigure(
            label: $label,
            value: $this->text->minorUnits($minor, $currency),
            change: null,
            changeIsGood: null,
            /* A plan figure is a person's decision, which is what `Manual` means in this product's vocabulary. */
            sourceLabel: ($source ?? MetricSource::Manual)->label(),
        );
    }

    /**
     * What the pacing column says.
     *
     * Three cases, and the middle one is the one worth a method: a budget nobody has approved has no pacing at
     * all, and the budgets module's own reason for that is better than a dash.
     */
    private function pacingCell(?AllocationPacingView $entry): string
    {
        if ($entry === null) {
            return '—';
        }

        return $entry->pacing?->status->label() ?? ($entry->unavailableReason ?? '—');
    }

    /**
     * The allocation by platform, with what has been spent against each.
     *
     * Walks the **budget's own allocations**, not the pacing view's. The budgets module reports pacing only
     * against an approved budget — a variance from an unapproved figure means nothing — so a draft budget comes
     * back with no pacing rows at all. The allocation, though, *is* the plan, and a media plan deck that showed
     * nothing because the budget had not been approved would be useless for the one occasion it is most needed:
     * presenting a plan for approval.
     *
     * So the plan is always drawn, and the spend column carries whatever pacing exists — or the reason it does
     * not.
     *
     * Null when nothing is allocated: a chart of one empty bar wastes a reader's attention, and the unallocated
     * figure on the previous slide has already said so.
     *
     * @param  list<AllocationView>  $allocations
     * @param  list<AllocationPacingView>  $pacing
     */
    private function byPlatform(array $allocations, array $pacing, string $currency): ?Slide
    {
        if ($allocations === []) {
            return null;
        }

        $spendByAllocation = [];

        foreach ($pacing as $entry) {
            $spendByAllocation[$entry->allocation->id] = $entry;
        }

        $labels = [];
        $planned = [];
        $plannedText = [];
        $rows = [];
        $hasPacing = false;

        foreach ($allocations as $allocation) {
            $entry = $spendByAllocation[$allocation->id] ?? null;
            $spent = $entry?->spend->minorUnits;
            $hasPacing = $hasPacing || $entry !== null;

            $labels[] = $allocation->label;
            $planned[] = (float) $allocation->amountMinor / 100;
            $plannedText[] = $this->text->minorUnits($allocation->amountMinor, $allocation->currency);

            $rows[] = [
                'label' => $allocation->label,
                'cells' => [
                    $this->text->minorUnits($allocation->amountMinor, $allocation->currency),
                    $spent === null ? '—' : $this->text->minorUnits($spent, $allocation->currency),
                    $this->pacingCell($entry),
                ],
            ];
        }

        return new Slide(
            kind: SlideKind::Breakdown,
            title: 'By platform',
            lead: null,
            series: [new SlideSeries(sprintf('Allocated (%s)', $currency), $planned, $plannedText)],
            categories: $labels,
            table: new SlideTable(['Platform', 'Allocated', 'Spent', 'Pacing'], $rows),
            notes: [
                $hasPacing
                    ? 'Spend is read from each platform; the allocation is the agency\'s own.'
                    : 'The allocation is the agency\'s own. No spend is shown against it — see the caveats.',
            ],
        );
    }
}
