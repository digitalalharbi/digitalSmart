<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Service;

/**
 * Finds the numbers in a piece of text.
 *
 * One implementation, used in both directions, and that is the point of it existing at all: the brief's list of
 * permitted figures and the guard's list of stated figures have to be produced by the same reading of what counts
 * as a number. Two regular expressions that almost agreed would make the guard reject prose for a difference
 * nobody intended — or, worse, accept a figure one of them could not see.
 *
 * It is deliberately greedy. A percentage sign, a currency symbol and an ordinal suffix all sit outside the
 * character class, so "25%", "SAR 4.20" and "3rd" each surface their figure — because each of them is a claim a
 * reader will act on.
 */
final readonly class FigureScanner
{
    /**
     * @return list<float>
     */
    public function in(string $text): array
    {
        $figures = [];

        foreach ($this->tokens($text) as $written) {
            $value = $this->toFloat($written);

            if ($value !== null) {
                $figures[] = $value;
            }
        }

        return $figures;
    }

    /**
     * The numbers as they were written, for naming the offending one in a refusal.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        preg_match_all('/-?\d+(?:[.,\x{00A0}\s]\d{3})*(?:[.,]\d+)?/u', $text, $matches);

        /** @var list<string> $found */
        $found = $matches[0] ?? [];

        return $found;
    }

    /**
     * One written number as a float, or null when it is not one.
     *
     * Separators out, decimal point in. A comma is a thousands separator in English and a decimal point in
     * several other locales, so the ambiguity is resolved by position: a comma followed by exactly three digits
     * and then a non-digit is a separator, and anything else is a decimal point.
     */
    public function toFloat(string $written): ?float
    {
        $normalised = preg_replace('/[\x{00A0}\s]/u', '', $written) ?? $written;
        $normalised = preg_replace('/,(\d{3})(?=\D|$)/', '$1', $normalised) ?? $normalised;
        $normalised = str_replace(',', '.', $normalised);

        if (! is_numeric($normalised)) {
            return null;
        }

        return (float) $normalised;
    }
}
