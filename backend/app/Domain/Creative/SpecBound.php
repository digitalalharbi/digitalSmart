<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * What one requirement of one specification asks for.
 *
 * Deliberately able to express "at least", "at most", "one of" and "between", because that is what the
 * platforms actually publish — a minimum resolution with no maximum, a maximum file size, a list of
 * accepted formats, a duration range. Forcing those into one shape would mean inventing bounds nobody
 * stated, and an invented maximum rejects files the platform would have accepted.
 *
 * Every field is nullable and an empty bound is legal: it means the page named the requirement without
 * giving a number, which happens, and the check then reports `Unchecked` rather than guessing.
 */
final readonly class SpecBound
{
    /** @param list<string>|null $allowed */
    public function __construct(
        public ?float $min = null,
        public ?float $max = null,
        public ?array $allowed = null,
        /**
         * How far a measurement may sit outside the bound and still pass, as a fraction.
         *
         * Only meaningful for an aspect ratio, where 1080×1919 is 9:16 for every practical purpose and
         * an exact comparison would reject most real exports. Zero everywhere else, because a file size
         * limit is a limit.
         */
        public float $tolerance = 0.0,
    ) {}

    /** Whether this bound says anything a measurement can be judged against. */
    public function isStated(): bool
    {
        return $this->min !== null || $this->max !== null || ($this->allowed !== null && $this->allowed !== []);
    }

    /** The bound in words, for the interface to show beside the measurement. */
    public function describe(?string $unit = null): string
    {
        $suffix = $unit === null ? '' : ' '.$unit;

        if ($this->allowed !== null && $this->allowed !== []) {
            return implode(', ', $this->allowed);
        }

        if ($this->min !== null && $this->max !== null) {
            return sprintf('%s–%s%s', $this->number($this->min), $this->number($this->max), $suffix);
        }

        if ($this->min !== null) {
            return sprintf('at least %s%s', $this->number($this->min), $suffix);
        }

        if ($this->max !== null) {
            return sprintf('at most %s%s', $this->number($this->max), $suffix);
        }

        return 'stated without a number';
    }

    /**
     * Judge a numeric measurement.
     *
     * Returns `Unchecked` for a measurement that is absent and `Unchecked` for a bound that states no
     * number — the two different ways of knowing nothing, both of which a pass would misrepresent.
     */
    public function judge(?float $measured): SpecVerdict
    {
        if (! $this->isStated() || $measured === null) {
            return SpecVerdict::Unchecked;
        }

        if ($this->min !== null && $measured < $this->min * (1 - $this->tolerance)) {
            return SpecVerdict::Fail;
        }

        if ($this->max !== null && $measured > $this->max * (1 + $this->tolerance)) {
            return SpecVerdict::Fail;
        }

        return SpecVerdict::Pass;
    }

    /** Judge a value against a list, case-insensitively — a file format, a codec. */
    public function judgeOneOf(?string $measured): SpecVerdict
    {
        if ($this->allowed === null || $this->allowed === [] || $measured === null || $measured === '') {
            return SpecVerdict::Unchecked;
        }

        $folded = strtolower(trim($measured));

        foreach ($this->allowed as $candidate) {
            if (strtolower(trim($candidate)) === $folded) {
                return SpecVerdict::Pass;
            }
        }

        return SpecVerdict::Fail;
    }

    /**
     * Judge an aspect ratio against the list of ratios the placement accepts.
     *
     * Compared as a number with the bound's tolerance rather than as a string, because `1080x1350` is
     * `4:5` and `1079x1350` is not — and an exact string comparison would reject nearly every real
     * export while a loose one would accept a square as a vertical.
     */
    public function judgeRatio(?float $width, ?float $height): SpecVerdict
    {
        if ($this->allowed === null || $this->allowed === [] || $width === null || $height === null) {
            return SpecVerdict::Unchecked;
        }

        if ($width <= 0 || $height <= 0) {
            return SpecVerdict::Unchecked;
        }

        $actual = $width / $height;
        $tolerance = $this->tolerance > 0 ? $this->tolerance : 0.02;

        foreach ($this->allowed as $candidate) {
            $target = self::ratioOf($candidate);

            if ($target !== null && abs($actual - $target) <= $target * $tolerance) {
                return SpecVerdict::Pass;
            }
        }

        return SpecVerdict::Fail;
    }

    /** `9:16` as a number, or null for anything this cannot read. */
    public static function ratioOf(string $ratio): ?float
    {
        $parts = explode(':', trim($ratio));

        if (count($parts) !== 2) {
            return null;
        }

        $width = (float) $parts[0];
        $height = (float) $parts[1];

        return $height > 0.0 && $width > 0.0 ? $width / $height : null;
    }

    private function number(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : (string) $value;
    }
}
