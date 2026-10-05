import { describe, expect, it } from 'vitest';
import { formatCompact, formatCurrency, formatNumber, formatPercent } from './format';

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
