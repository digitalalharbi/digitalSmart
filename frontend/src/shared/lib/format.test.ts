import { describe, expect, it } from 'vitest';
import { formatCompact, formatCurrency, formatMinor, formatNumber, formatPercent } from './format';

describe('formatters', () => {
  it('formats integers for English', () => {
    expect(formatNumber(1234567, 'en')).toBe('1,234,567');
  });

  it('uses Latin digits in Arabic so numbers reconcile with platform dashboards', () => {
    const formatted = formatNumber(1234, 'ar');
    expect(formatted).toMatch(/1/);
    expect(formatted).not.toMatch(/[٠-٩]/);
  });

  it('keeps the minor unit on every amount, large or small', () => {
    /*
     * This replaces an earlier rule that dropped the decimals above a thousand
     * for density. It was wrong for this product: these figures are reconciled
     * against a platform's own invoice, and a column where 840.25 keeps its cents
     * while 12,500.00 does not is inconsistent to scan and short of the precision
     * someone is checking. Dense tiles use formatCompact, which is an explicit
     * choice rather than a silent threshold.
     */
    expect(formatCurrency(12500, 'SAR', 'en')).toMatch(/12,500\.00/);
    expect(formatCurrency(840.25, 'SAR', 'en')).toMatch(/840\.25/);
  });

  it('can be asked for whole units explicitly', () => {
    expect(formatCurrency(12500, 'SAR', 'en', 0)).not.toMatch(/\./);
  });

  it('takes its decimals from the currency, not from a constant', () => {
    /*
     * A yen has no subdivision and a Kuwaiti dinar has a thousand fils. Two decimals is
     * the right default for most of the world and is a fabricated precision on one and a
     * lost fils on the other.
     */
    expect(formatCurrency(1200, 'JPY', 'en')).toMatch(/1,200(?!\.)/);
    expect(formatCurrency(1.234, 'KWD', 'en')).toMatch(/1\.234/);
    expect(formatCurrency(840.25, 'SAR', 'en')).toMatch(/840\.25/);
  });
});

describe('formatMinor', () => {
  /*
   * The regression these tests exist for: the frontend used to divide every amount by a
   * hundred, whatever the currency, while the server stored it to the currency's own ISO
   * 4217 exponent. A yen amount was therefore shown at a hundredth of its value and a
   * dinar at a thousandth — on a figure a client is billed for.
   */
  it('shows a yen with no decimals and at full value', () => {
    const rendered = formatMinor(1200, 'JPY', 'en');

    expect(rendered).toMatch(/1,200/);
    // The old behaviour: ¥1,200 rendered as 12.00.
    expect(rendered).not.toMatch(/12\.00/);
  });

  it('divides a Kuwaiti dinar by a thousand, to three decimals', () => {
    const rendered = formatMinor(1200, 'KWD', 'en');

    expect(rendered).toMatch(/1\.200/);
    // The old behaviour: 1.200 KWD rendered as 12.00.
    expect(rendered).not.toMatch(/12\.00/);
  });

  it('divides a riyal by a hundred, to two decimals', () => {
    expect(formatMinor(123_456, 'SAR', 'en')).toMatch(/1,234\.56/);
  });

  it('names the currency on every amount', () => {
    // An amount labelled with the wrong currency is worse than an amount with none.
    expect(formatMinor(1200, 'JPY', 'en')).toMatch(/¥/);
    expect(formatMinor(1200, 'KWD', 'en')).toMatch(/KWD/);
    expect(formatMinor(123_456, 'SAR', 'en')).toMatch(/SAR/);
  });

  it('uses Latin digits in Arabic, as the rest of the product does', () => {
    const rendered = formatMinor(123_456, 'SAR', 'ar');

    expect(rendered).toMatch(/1,234\.56/);
    expect(rendered).not.toMatch(/[٠-٩]/);
  });

  it('formats percentages with one fraction digit by default', () => {
    expect(formatPercent(0.1234, 'en')).toBe('12.3%');
  });

  it('compacts large numbers for dense tiles', () => {
    expect(formatCompact(1_240_000, 'en')).toBe('1.2M');
  });

  it('falls back to English formatting for an unknown locale', () => {
    expect(formatNumber(1000, 'zz')).toBe('1,000');
  });
});
