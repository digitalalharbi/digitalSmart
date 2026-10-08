<?php

declare(strict_types=1);

namespace App\Application\Shared\Service;

use App\Application\Analytics\DTO\MetricValue;
use App\Domain\Analytics\MetricFormat;

/**
 * A figure, written for a person to read out loud.
 *
 * Needed because a deck is rendered twice — into a PPTX file and into a page — and a figure formatted
 * independently in each would eventually differ: a different rounding, a different thousands separator, a currency
 * still in minor units in one of them. Formatting once here means the file and the screen agree, and neither
 * renderer is permitted to do arithmetic.
 *
 * It is **not** the same formatter as `ReportCsv`'s, and that is deliberate rather than duplication. A CSV is read
 * by a spreadsheet: no separators, no symbols, a plain decimal point, because anything else arrives as text and
 * stops being a number. This is read by a person in a room.
 *
 * **Western digits, always.** The product already decided this for its screens and has a test holding it: a
 * client reconciling an agency's figure against a platform dashboard is comparing two strings, and Eastern Arabic
 * numerals in one of them make that comparison a transcription exercise.
 */
final readonly class MetricText
{
    /** Money in major units; a ratio as a percentage; a count with separators. */
    public function value(MetricValue $value): string
    {
        $raw = $value->value;

        if ($raw === null) {
            return '—';
        }

        return match ($value->format) {
            MetricFormat::Currency => $this->money($value),
            MetricFormat::Percentage => $this->decimal((float) $raw * 100, 2).'%',
            MetricFormat::Ratio => $this->decimal((float) $raw, 2),
            MetricFormat::Count => $this->decimal((float) $raw, 0),
        };
    }

    /**
     * The change against the comparison period.
     *
     * Null rather than "0%" when there is none: no comparison and no change are different facts, and
     * `MetricValue::changeRatio()` already returns null from a base of zero — everything is an infinite increase
     * on nothing.
     */
    public function change(MetricValue $value): ?string
    {
        $ratio = $value->changeRatio();

        if ($ratio === null) {
            return null;
        }

        return sprintf('%s%s%%', $ratio > 0 ? '+' : '', $this->decimal($ratio * 100, 1));
    }

    /** A bare number, for an axis label or a plan figure that is not a `MetricValue`. */
    public function number(float $value, int $decimals = 0): string
    {
        return $this->decimal($value, $decimals);
    }

    /** Minor units to major, with the currency named. A deck is read by somebody who will quote it. */
    public function money(MetricValue $value): string
    {
        $raw = $value->value;

        if ($raw === null) {
            return '—';
        }

        /*
         * A currency figure is in minor units throughout this product — halalas, not riyals — so a deck that
         * printed the stored value would overstate every amount by a hundred.
         */
        $written = $this->decimal((float) $raw / 100, 2);

        return $value->currency === null ? $written : sprintf('%s %s', $value->currency, $written);
    }

    /** Minor units to major for a figure that carries its currency separately. */
    public function minorUnits(int|float $minor, ?string $currency): string
    {
        $written = $this->decimal((float) $minor / 100, 2);

        return $currency === null ? $written : sprintf('%s %s', $currency, $written);
    }

    private function decimal(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', ',');
    }
}
