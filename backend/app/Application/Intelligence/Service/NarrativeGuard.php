<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Service;

/**
 * Refuses a narrative that states a number the evidence does not support.
 *
 * The centrepiece of this module, and the reason a language model is allowed near a client's figures at all.
 *
 * Every number the product computed is in the brief's `allowedFigures`. Every number in the model's prose is
 * extracted here and matched against that list. Anything unmatched means the model has produced a figure — by
 * arithmetic it was not asked to do, by misreading, or by invention — and the narrative is discarded. The reader
 * gets the product's own sentences and is told the model's answer was refused.
 *
 * Three decisions in the matching are worth stating.
 *
 * **It compares numerically, and the tolerance is the precision the prose chose.** "4.2" and "4.20" are the same
 * figure, and a string comparison would reject a model for rounding. So a figure written to two decimals may be
 * out by half of the last place, and one written to none may be out by half a unit — which is exactly what
 * "rounded" means. A relative tolerance was the obvious alternative and the wrong one: a tenth of a percent of
 * 1,250,000 is 1,250, so "1,250,001" would have passed as a restatement of "1,250,000", and the whole point of
 * this class is that it does not.
 *
 * **Thousands separators are normalised, in both directions.** A model writing "1,250,000" or "1 250 000" is
 * writing the figure it was given. A model writing "1,250,001" is not. The reading of what counts as a number is
 * `FigureScanner`'s, shared with the brief that assembled the permitted list — two regular expressions that
 * almost agreed would reject prose for a difference nobody intended.
 *
 * **Years are not an exemption.** It would be convenient to allow four-digit numbers that look like years, and
 * that is exactly the hole through which "up 2024%" walks. If prose needs a date, the brief gives it as evidence.
 */
final readonly class NarrativeGuard
{
    /**
     * The floor on how close counts, for a figure written with no decimals at all.
     *
     * Half a unit: "4" is a fair way to write 4.3, and is not a fair way to write 5.
     */
    private const float HALF_A_UNIT = 0.5;

    public function __construct(private FigureScanner $figures) {}

    /**
     * @param  list<float>  $allowed
     * @return array{ok: bool, offending: list<string>}
     */
    public function inspect(string $narrative, array $allowed): array
    {
        $offending = [];

        foreach ($this->figures->tokens($narrative) as $written) {
            $value = $this->figures->toFloat($written);

            if ($value === null) {
                continue;
            }

            if (! $this->isAllowed($value, $allowed, $this->toleranceFor($written))) {
                $offending[] = $written;
            }
        }

        return ['ok' => $offending === [], 'offending' => array_values(array_unique($offending))];
    }

    /**
     * How far out a figure written like this may be.
     *
     * Half of the last decimal place it was written to. Two decimals buys 0.005, none buys 0.5 — the error a
     * reader would accept from somebody rounding, and no more.
     */
    private function toleranceFor(string $written): float
    {
        $decimals = 0;

        if (preg_match('/[.,](\\d+)$/', $written, $matches) === 1) {
            $decimals = strlen($matches[1]);
        }

        return self::HALF_A_UNIT / (10 ** $decimals);
    }

    /** @param  list<float>  $allowed */
    private function isAllowed(float $value, array $allowed, float $tolerance): bool
    {
        foreach ($allowed as $candidate) {
            if (abs($value - $candidate) <= $tolerance) {
                return true;
            }
        }

        return false;
    }
}
