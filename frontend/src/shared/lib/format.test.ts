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

  it('formats currency without stray fraction digits on large amounts', () => {
    expect(formatCurrency(12500, 'SAR', 'en')).toMatch(/12,500/);
    expect(formatCurrency(12500, 'SAR', 'en')).not.toMatch(/\.00/);
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
