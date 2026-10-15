<?php

declare(strict_types=1);

namespace App\Domain\Advertising\ValueObject;

/**
 * How many decimal places a currency's minor unit has, per ISO 4217.
 *
 * Most of the world uses two, which is why "multiply by 100" survives so long in
 * so many codebases. It is still wrong: a yen has no subdivision at all, and a
 * Kuwaiti dinar has a thousand fils. Treating JPY 1200 as two decimal places
 * bills the client a hundred times the spend, and a Gulf agency reports in KWD,
 * BHD and OMR often enough that this is not a hypothetical.
 *
 * The table holds only the currencies that are *not* two, because listing every
 * two-decimal currency would be a list nobody maintains and a default nobody
 * reads. A code that is absent is two — including codes this table has never
 * heard of, which is the honest answer for an unknown currency rather than a
 * refusal that would stop a sync over a figure we could still convert.
 *
 * @see https://en.wikipedia.org/wiki/ISO_4217 for the published exponents
 */
final class CurrencyExponent
{
    /**
     * The ISO 4217 default, used for every code not listed below.
     */
    public const DEFAULT = 2;

    /**
     * Currencies with no minor unit: the amount is already in whole units.
     *
     * @var list<string>
     */
    private const ZERO = [
        'BIF', // Burundian franc
        'CLP', // Chilean peso
        'DJF', // Djiboutian franc
        'GNF', // Guinean franc
        'ISK', // Icelandic króna
        'JPY', // Japanese yen
        'KMF', // Comorian franc
        'KRW', // South Korean won
        'PYG', // Paraguayan guaraní
        'RWF', // Rwandan franc
        'UGX', // Ugandan shilling
        'UYI', // Uruguayan peso en unidades indexadas
        'VND', // Vietnamese đồng
        'VUV', // Vanuatu vatu
        'WST', // Samoan tālā
        'XAF', // Central African CFA franc
        'XOF', // West African CFA franc
        'XPF', // CFP franc
    ];

    /**
     * Currencies subdivided into thousandths — the Gulf and North Africa.
     *
     * @var list<string>
     */
    private const THREE = [
        'BHD', // Bahraini dinar
        'IQD', // Iraqi dinar
        'JOD', // Jordanian dinar
        'KWD', // Kuwaiti dinar
        'LYD', // Libyan dinar
        'OMR', // Omani rial
        'TND', // Tunisian dinar
    ];

    /**
     * The two four-decimal units ISO 4217 publishes, both Latin American
     * inflation-indexed accounting units rather than cash.
     *
     * @var list<string>
     */
    private const FOUR = [
        'CLF', // Unidad de Fomento
        'UYW', // Unidad previsional
    ];

    /** A table, not a thing to hold: there is nothing to construct. */
    private function __construct() {}

    /**
     * The exponent for a currency code, defaulting to two for anything unlisted.
     *
     * Accepts the code in any case and with surrounding space, because it arrives
     * from a provider's JSON rather than from us.
     */
    public static function for(string $currency): int
    {
        $code = strtoupper(trim($currency));

        return match (true) {
            in_array($code, self::ZERO, true) => 0,
            in_array($code, self::THREE, true) => 3,
            in_array($code, self::FOUR, true) => 4,
            default => self::DEFAULT,
        };
    }
}
