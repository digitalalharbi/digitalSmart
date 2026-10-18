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
 *
 * How many minor units make a major one is the currency's business, not this
 * class's: a yen has none, a Kuwaiti dinar has a thousand. Every conversion here
 * asks {@see CurrencyExponent} rather than assuming two, so JPY 1200 is 1200
 * minor units and not 120000. A caller may still pass an explicit `$scale` when
 * it knows better than the table — a provider reporting in a fixed precision
 * regardless of currency — but nothing has to pass one to be right.
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

    /**
     * Google and X report in micros — one millionth of the major unit.
     *
     * A micro is defined against the *major* unit, so the divisor is however many
     * micros make one minor unit: 10^4 for a two-decimal currency, 10^6 for yen,
     * 10^3 for a dinar. Hard-coding 10^4 would read JPY 1 as 100 yen.
     */
    public static function fromMicros(int $micros, string $currency, ?int $scale = null): self
    {
        $code = strtoupper(trim($currency));

        return new self(intdiv($micros, 10 ** (6 - ($scale ?? CurrencyExponent::for($code)))), $code);
    }

    /**
     * From a provider's decimal string, such as Meta's "123.45".
     *
     * Rounds half-up at the minor unit, which is what every platform's own
     * billing does. Which place that is comes from the currency unless the caller
     * names a scale.
     */
    public static function fromDecimalString(string $amount, string $currency, ?int $scale = null): self
    {
        $normalised = trim($amount);
        $code = strtoupper(trim($currency));

        if ($normalised === '' || ! is_numeric($normalised)) {
            return self::zero($code);
        }

        $places = $scale ?? CurrencyExponent::for($code);

        return new self((int) round(((float) $normalised) * (10 ** $places)), $code);
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
     *
     * The number of places follows the currency unless the caller names a scale,
     * so JPY prints "1200" and KWD "1.234" without either call site knowing it
     * had to ask.
     */
    public function toDecimal(?int $scale = null): string
    {
        $places = $scale ?? CurrencyExponent::for($this->currency);
        $divisor = 10 ** $places;
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        if ($places === 0) {
            return $sign.(string) $absolute;
        }

        return sprintf(
            '%s%d.%0'.$places.'d',
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
     *
     * Takes its scale from the currency like the rest of the class; leaving this
     * one method on a fixed two would put the bug back for whoever charts yen.
     */
    public function toFloat(?int $scale = null): float
    {
        return $this->minorUnits / (10 ** ($scale ?? CurrencyExponent::for($this->currency)));
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
