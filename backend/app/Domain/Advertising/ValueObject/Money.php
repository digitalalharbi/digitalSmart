<?php

declare(strict_types=1);

namespace App\Domain\Advertising\ValueObject;

use App\Domain\Shared\Exception\CurrencyMismatch;

/**
 * An amount in minor units with its currency.
 *
 * Integer minor units, never floats: a float cannot hold 0.1 exactly, and
 * summing thousands of ad-spend rows in floating point produces totals that
 * drift from the platform's own. Providers agree on this — Google returns
 * micros, Meta returns minor-unit strings, X returns local micros — so the
 * adapter converts once, at the boundary.
 *
 * Arithmetic refuses to mix currencies rather than silently producing a
 * meaningless number. Converting is a deliberate act with an explicit rate.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, strtoupper(trim($currency)));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper(trim($currency)));
    }

    /** Google and X report in micros — one millionth of the major unit. */
    public static function fromMicros(int $micros, string $currency): self
    {
        return new self(intdiv($micros, 10_000), strtoupper(trim($currency)));
    }

    /**
     * From a provider's decimal string, such as Meta's "123.45".
     *
     * Rounds half-up at the minor unit, which is what every platform's own
     * billing does.
     */
    public static function fromDecimalString(string $amount, string $currency, int $scale = 2): self
    {
        $normalised = trim($amount);

        if ($normalised === '' || ! is_numeric($normalised)) {
            return self::zero($currency);
        }

        return new self((int) round(((float) $normalised) * (10 ** $scale)), strtoupper(trim($currency)));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    /**
     * The exact amount as a decimal string, for display and serialisation.
     *
     * A string rather than a float, built with integer arithmetic: a class whose
     * reason to exist is that floats cannot hold 0.1 must not hand one back at
     * the last step. 1234 minor units is "12.34", not 12.339999999999999.
     */
    public function toDecimal(int $scale = 2): string
    {
        $divisor = 10 ** $scale;
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        if ($scale === 0) {
            return $sign.(string) $absolute;
        }

        return sprintf(
            '%s%d.%0'.$scale.'d',
            $sign,
            intdiv($absolute, $divisor),
            $absolute % $divisor,
        );
    }

    /**
     * As a float, for ratio maths and charts only.
     *
     * Named so the lossiness is visible at the call site. Never use it to store
     * or accumulate an amount — that is what minorUnits is for.
     */
    public function toFloat(int $scale = 2): float
    {
        return $this->minorUnits / (10 ** $scale);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
