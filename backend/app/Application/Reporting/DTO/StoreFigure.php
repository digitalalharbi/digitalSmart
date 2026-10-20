<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Domain\Advertising\ValueObject\CurrencyExponent;

/**
 * One store figure: a count or an amount in minor units, with the words for it.
 *
 * `value` is null when the figure does not exist — an average order value over no orders — and never zero in its
 * place: "no average" and "an average of nothing" are different statements.
 */
final readonly class StoreFigure
{
    public function __construct(
        public string $key,
        public string $label,
        /** `money` is in minor units of `currency`; `count` is a plain number. */
        public string $kind,
        public int|float|null $value,
        public ?string $currency = null,
    ) {}

    /** Decimals the figure is written with: the currency's own for money, none for a count. */
    public function decimals(): int
    {
        return $this->kind === 'money' && $this->currency !== null ? CurrencyExponent::for($this->currency) : 0;
    }

    /** The figure in major units — what a person reads — or null when it does not exist. */
    public function major(): int|float|null
    {
        if ($this->value === null) {
            return null;
        }

        return $this->kind === 'money' ? $this->value / (10 ** $this->decimals()) : $this->value;
    }

    /** Written for a person: grouped digits, the currency's own decimals, no unit for a count. */
    public function text(): ?string
    {
        $major = $this->major();

        if ($major === null) {
            return null;
        }

        $number = number_format($major, $this->decimals());

        return $this->kind === 'money' && $this->currency !== null ? $number.' '.$this->currency : $number;
    }
}
