import { describe, expect, it } from 'vitest';
import { formatChange, formatMetric, MISSING } from './format';
import type { MetricValue } from './types';

function value(overrides: Partial<MetricValue> = {}): MetricValue {
  return {
    metric: 'spend',
    label: 'Spend',
    value: 123_456,
    format: 'currency',
    precision: 2,
    is_minor_units: true,
    currency: 'SAR',
    source: 'live',
    source_label: 'Live',
    requires_label: false,
    unavailable_reason: null,
    comparison_value: null,
    change_ratio: null,
    change_is_good: null,
    lower_is_better: false,
    ...overrides,
  };
}

describe('formatMetric', () => {
  it('renders a missing figure as missing, never as zero', () => {
    /*
     * The single most important assertion in this file. Coercing a null to 0
     * anywhere turns "the platform does not report this" into "this performed at
     * zero" — a claim about a campaign rather than a statement about the data.
     */
    expect(formatMetric(value({ value: null }), 'en')).toBe(MISSING);
    expect(formatMetric(value({ value: null }), 'en')).not.toBe('0');
  });

  it('distinguishes a reported zero from a missing figure', () => {
    // Zero is a real result and must read as one. Counts arrive with precision 0.
    expect(
      formatMetric(value({ value: 0, format: 'count', is_minor_units: false, precision: 0 }), 'en'),
    ).toBe('0');
  });

  it('divides minor units exactly once', () => {
    // 123,456 minor units is 1,234.56 — not 123,456 and not 12.3456.
    expect(formatMetric(value(), 'en')).toContain('1,234.56');
  });

  it('keeps the cents above a thousand, because an invoice does', () => {
    /*
     * A column where 840.25 keeps its cents and 1,234.56 rounds to 1,235 is both
     * inconsistent to scan and short of the precision someone reconciling against
     * a platform's invoice is checking.
     */
    expect(formatMetric(value({ value: 84_025 }), 'en')).toContain('840.25');
    expect(formatMetric(value({ value: 123_456 }), 'en')).toContain('1,234.56');
  });

  it('reads percentages as the API sends them', () => {
    // 2.35 means 2.35%, not 235%.
    expect(
      formatMetric(value({ format: 'percentage', value: 2.35, is_minor_units: false }), 'en'),
    ).toBe('2.35%');
  });

  it('marks a ratio as a multiple', () => {
    expect(
      formatMetric(
        value({ format: 'ratio', value: 3.8, is_minor_units: false, precision: 2 }),
        'en',
      ),
    ).toBe('3.80×');
  });

  it('uses Latin digits in Arabic, because platforms do', () => {
    const rendered = formatMetric(
      value({ format: 'count', value: 1234, is_minor_units: false, precision: 0 }),
      'ar',
    );

    /*
     * Media buyers reconcile these figures against Meta's and Google's own
     * dashboards, which render Latin digits in every locale. Arabic-Indic digits
     * would make that comparison a transliteration exercise.
     */
    expect(rendered).toMatch(/[0-9]/);
    expect(rendered).not.toMatch(/[٠-٩]/);
  });
});

describe('formatChange', () => {
  it('signs an increase and leaves a decrease to the minus', () => {
    expect(formatChange(value({ change_ratio: 0.235 }), 'en')).toBe('+23.5%');
    expect(formatChange(value({ change_ratio: -0.112 }), 'en')).toBe('-11.2%');
  });

  it('returns nothing when there is nothing to compare', () => {
    // "Up from nothing" has no percentage. The server already decided this; the
    // formatter must not invent one.
    expect(formatChange(value({ change_ratio: null }), 'en')).toBeNull();
  });
});
