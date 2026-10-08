<?php

declare(strict_types=1);

namespace App\Application\Intelligence\DTO;

use App\Application\Intelligence\Service\FigureScanner;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;

/**
 * Everything a language model is given, and nothing else.
 *
 * Three properties of this object are the security and honesty design of the whole module.
 *
 * **It carries no client identity.** Not the client's name, not a project name, not a campaign name unless that
 * name is already the subject of a finding the agency wrote. Sending a third party "Alhambra's cost per click
 * doubled" discloses a commercial fact about a named company; sending "the account's cost per click doubled"
 * discloses a number. The interface puts the client's name back around the prose, where it never left the
 * workspace.
 *
 * **It carries no figure the product did not compute.** The findings are already complete — headline, detail,
 * evidence — before this object exists. The model's job is to join them into readable prose, not to work anything
 * out.
 *
 * **It carries the list of numbers the prose is allowed to contain.** The rule is one sentence: **the model may
 * restate the brief and nothing more.** `allowedFigures` is therefore every figure in the evidence *and* every
 * number appearing anywhere in the brief's own text — the period, the headlines, the labels — read by the same
 * `FigureScanner` the guard uses. Anything else in the narrative is a figure the model produced rather than
 * repeated, and {@see \App\Application\Intelligence\Service\NarrativeGuard} rejects it.
 */
final readonly class NarrativeBrief
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<float>  $allowedFigures
     */
    private function __construct(
        public AssistantMode $mode,
        /** The period, as words. No dates belonging to a named client are needed to write a sentence about it. */
        public string $period,
        public array $findings,
        public array $allowedFigures,
    ) {}

    /**
     * @param  list<Finding>  $findings
     */
    public static function of(
        AssistantMode $mode,
        string $period,
        array $findings,
        int $periodDays,
        FigureScanner $figures,
    ): self {
        $allowed = [];

        foreach ($figures->in($period) as $figure) {
            $allowed[] = $figure;
        }

        foreach ($findings as $finding) {
            foreach ($finding->figures() as $figure) {
                $allowed[] = $figure;
            }

            /*
             * And every number in the words of the brief. A headline, a detail or a label that mentions a figure
             * is a figure the model is being shown, and prose that repeats it is prose that repeated the brief.
             */
            foreach ([$finding->subject, $finding->headline, $finding->detail, $finding->recommendation ?? ''] as $text) {
                foreach ($figures->in($text) as $figure) {
                    $allowed[] = $figure;
                }
            }

            foreach ($finding->evidence as $evidence) {
                foreach ($figures->in($evidence->label.' '.$evidence->period.' '.($evidence->reason ?? '')) as $figure) {
                    $allowed[] = $figure;
                }
            }
        }

        /*
         * The three the brief states of its own accord: how long the period was, how many findings there are,
         * and how many of them need a decision. Everything else has to come from a finding.
         */
        $allowed[] = (float) $periodDays;
        $allowed[] = (float) count($findings);
        $allowed[] = (float) count(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->severity->needsAttention(),
        ));

        return new self($mode, $period, $findings, array_values(array_unique($allowed, SORT_REGULAR)));
    }

    /**
     * Every piece of evidence across every finding, for an adapter that needs to render it.
     *
     * @return list<Evidence>
     */
    public function evidence(): array
    {
        $evidence = [];

        foreach ($this->findings as $finding) {
            foreach ($finding->evidence as $item) {
                $evidence[] = $item;
            }
        }

        return $evidence;
    }
}
