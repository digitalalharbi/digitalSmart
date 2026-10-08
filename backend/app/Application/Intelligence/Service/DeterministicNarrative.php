<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Service;

use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;

/**
 * The prose the product writes for itself, with no model involved.
 *
 * Not a degraded mode. It is the **default**, and it is what a reader gets whenever no model is configured, the
 * model cannot be reached, or the model's answer was refused. The findings are complete before any of this: the
 * narrative joins them into something readable and adds nothing.
 *
 * It is held to the same rule as a model's narrative, and a test asserts it: **every number here comes from the
 * brief's allowed figures.** The only two it states of its own accord are how many findings there are and how
 * many need attention — both of which the brief allows, because the brief put them there. The headlines and
 * details are product-authored sentences and deliberately contain no digits at all, which is why joining them is
 * safe.
 */
final readonly class DeterministicNarrative
{
    public function write(NarrativeBrief $brief): string
    {
        if ($brief->findings === []) {
            return 'Nothing was found for this period. That is not the same as nothing having happened: it means '
                .'no analyser matched, and the figures themselves are on the analytics surface.';
        }

        $attention = array_values(array_filter(
            $brief->findings,
            static fn (Finding $finding): bool => $finding->severity->needsAttention(),
        ));

        $lines = [$this->lead($brief, count($attention))];

        foreach ($brief->findings as $finding) {
            $lines[] = sprintf('%s %s', $finding->headline, $finding->detail);
        }

        $insufficient = array_values(array_filter(
            $brief->findings,
            static fn (Finding $finding): bool => $finding->severity === FindingSeverity::Insufficient,
        ));

        if ($insufficient !== []) {
            /*
             * Said at the end rather than left to the reader to notice. A list in which some questions were not
             * answered reads, to somebody skimming, exactly like a list in which they were answered favourably.
             */
            $lines[] = 'Some of what was looked at could not be answered from this period\'s data, and is marked '
                .'as such rather than left out.';
        }

        return implode("\n\n", $lines);
    }

    private function lead(NarrativeBrief $brief, int $attention): string
    {
        $count = count($brief->findings);

        if ($attention === 0) {
            return sprintf(
                'Across %d finding%s for %s, nothing needs a decision this week.',
                $count,
                $count === 1 ? '' : 's',
                $brief->period,
            );
        }

        return sprintf(
            'Of %d finding%s for %s, %d need%s a decision.',
            $count,
            $count === 1 ? '' : 's',
            $brief->period,
            $attention,
            $attention === 1 ? 's' : '',
        );
    }
}
