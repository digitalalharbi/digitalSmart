import { describe, expect, it } from 'vitest';
import BACKEND_TABLE from '../../../../backend/app/Domain/Advertising/ValueObject/CurrencyExponent.php?raw';
import {
  currencyExponent,
  DEFAULT_CURRENCY_EXPONENT,
  fromMinor,
  minorUnitsPerMajor,
  toDecimal,
  toMinor,
} from './money';

describe('currencyExponent', () => {
  it('reads the minor unit off the currency rather than assuming a hundred', () => {
    /*
     * The three cases this product actually bills in. A yen has no subdivision, a dinar
     * has a thousand fils, and a riyal has a hundred halalas — so one constant cannot
     * serve all three, and the one that used to be here reported a yen a hundredfold
     * over and a dinar a thousandfold over.
     */
    expect(currencyExponent('JPY')).toBe(0);
    expect(currencyExponent('KWD')).toBe(3);
    expect(currencyExponent('SAR')).toBe(2);
  });

  it('treats an unlisted or absent currency as two, the ISO default', () => {
    // What the server assumed when it stored the figure, so the two agree.
    expect(currencyExponent('ZZZ')).toBe(DEFAULT_CURRENCY_EXPONENT);
    expect(currencyExponent(null)).toBe(DEFAULT_CURRENCY_EXPONENT);
    expect(currencyExponent(undefined)).toBe(DEFAULT_CURRENCY_EXPONENT);
  });

  it('accepts a code as a provider sends it, in any case and with space', () => {
    expect(currencyExponent(' jpy ')).toBe(0);
    expect(currencyExponent('kwd')).toBe(3);
  });

  it('gives the divisor as a power of ten', () => {
    expect(minorUnitsPerMajor('JPY')).toBe(1);
    expect(minorUnitsPerMajor('SAR')).toBe(100);
    expect(minorUnitsPerMajor('KWD')).toBe(1_000);
    expect(minorUnitsPerMajor('CLF')).toBe(10_000);
  });

  it('does not take the scale from Intl, which is CLDR and disagrees with ISO', () => {
    /*
     * The reason this table exists at all. `Intl.NumberFormat` carries a fraction-digit
     * count per currency, but it is CLDR's cash-display convention rather than the ISO
     * 4217 exponent the server stored the figure with, and the two disagree for eighteen
     * of the currencies this engine knows — including IQD, where CLDR says none and ISO
     * publishes three.
     *
     * Reading the scale off Intl would therefore report an Iraqi dinar budget a thousand
     * times over, and would do it differently depending on the reader's browser version.
     * This test documents the disagreement rather than the agreement: if a future ICU
     * changes its mind about IQD, nothing about our arithmetic should move.
     */
    // `number | undefined`: Intl does not promise a digit count for every currency.
    const intlDigits = (currency: string): number | undefined =>
      new Intl.NumberFormat('en-US', { style: 'currency', currency }).resolvedOptions()
        .maximumFractionDigits;

    expect(intlDigits('IQD')).not.toBe(currencyExponent('IQD'));
    expect(currencyExponent('IQD')).toBe(3);
  });
});

describe('the table against the backend', () => {
  it('holds exactly the exponents CurrencyExponent.php does', () => {
    /*
     * The whole point of one table rather than two: a frontend that drifts from the
     * server's exponent does not render a slightly different figure, it renders one that
     * is out by a factor of ten — and it is the client's invoice that disagrees. Parsed
     * from the PHP rather than restated here, because a hand-copied expectation is a
     * third copy of the same table.
     */
    /*
     * The server's own file as text, through Vite's `?raw` rather than `node:fs`: the
     * app's TypeScript project deliberately carries no node types, and a test importing
     * them would be the first file under `src` able to touch the filesystem.
     */
    const php = BACKEND_TABLE;

    const codesIn = (constant: string): string[] => {
      const block = new RegExp(`const ${constant} = \\[(.*?)\\];`, 's').exec(php)?.[1];

      expect(block, `${constant} is no longer a list in CurrencyExponent.php`).toBeDefined();

      return [...(block ?? '').matchAll(/'([A-Z]{3})'/g)].map((match) => match[1] as string);
    };

    const backend = new Map<string, number>([
      ...codesIn('ZERO').map((code): [string, number] => [code, 0]),
      ...codesIn('THREE').map((code): [string, number] => [code, 3]),
      ...codesIn('FOUR').map((code): [string, number] => [code, 4]),
    ]);

    // Neither side may know a currency the other does not.
    expect(backend.size).toBeGreaterThan(20);

    for (const [code, exponent] of backend) {
      expect(currencyExponent(code), `${code} disagrees with the backend`).toBe(exponent);
    }

    const defaults = /public const DEFAULT = (\d+);/.exec(php)?.[1];

    expect(Number(defaults)).toBe(DEFAULT_CURRENCY_EXPONENT);
  });
});

describe('converting minor units', () => {
  it('divides by the currency, not by a hundred', () => {
    expect(fromMinor(1200, 'JPY')).toBe(1200);
    expect(fromMinor(1200, 'KWD')).toBe(1.2);
    expect(fromMinor(1200, 'SAR')).toBe(12);
  });

  it('reads a typed amount into the minor unit the currency has', () => {
    expect(toMinor('1200', 'JPY')).toBe(1200);
    expect(toMinor('1.200', 'KWD')).toBe(1200);
    expect(toMinor('12.00', 'SAR')).toBe(1200);
  });

  it('rounds at the minor unit the currency actually has', () => {
    // A yen has no subdivision to round to, so a typed fraction lands on the yen.
    expect(toMinor('1200.6', 'JPY')).toBe(1201);
    // A dinar rounds at the fils; 33.335 is a typo and the nearest halala is kinder.
    expect(toMinor('1.2345', 'KWD')).toBe(1235);
    expect(toMinor('33.335', 'SAR')).toBe(3334);
  });

  it('treats an unfinished entry as nothing rather than NaN', () => {
    /*
     * Somebody typing into an empty amount field should see a remainder, not a screen
     * reading "NaN over the budget".
     */
    expect(toMinor('', 'SAR')).toBe(0);
    expect(toMinor('abc', 'KWD')).toBe(0);
    expect(toMinor('.', 'JPY')).toBe(0);
  });

  it('round-trips through the decimal string the API takes', () => {
    /*
     * Both directions, per currency, because this is the pair that reaches the server:
     * a planner types a decimal, it is stored in minor units, and it comes back to be
     * typed over. A yen must not acquire two decimals it does not have, and a dinar must
     * not lose its third.
     */
    expect(toDecimal(toMinor('1200', 'JPY'), 'JPY')).toBe('1200');
    expect(toDecimal(toMinor('1.234', 'KWD'), 'KWD')).toBe('1.234');
    expect(toDecimal(toMinor('1500.50', 'SAR'), 'SAR')).toBe('1500.50');

    expect(toDecimal(1200, 'JPY')).toBe('1200');
    expect(toDecimal(1234, 'KWD')).toBe('1.234');
    expect(toDecimal(1, 'SAR')).toBe('0.01');
  });
});
