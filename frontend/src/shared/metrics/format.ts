import { formatCompact, formatCurrency, formatNumber, formatPercent } from '@/shared/lib/format';
import type { MetricValue } from './types';

/**
 * Render one figure, honouring its own format and its nullability.
 *
 * The em dash is deliberate and is the whole point of this function: a missing
 * figure must look missing. Returning '0' would turn "the platform does not report
 * this" into "this performed at zero", which is a claim about a campaign rather
 * than a statement about the data.
 */
export const MISSING = '—';

export function formatMetric(value: MetricValue, locale: string, compact = false): string {
  if (value.value === null) {
    return MISSING;
  }

  switch (value.format) {
    case 'currency': {
      // Minor units on the wire; divided once, here.
      const major = value.is_minor_units ? value.value / 100 : value.value;
      const currency = value.currency ?? 'USD';

      return compact
        ? `${formatCompact(major, locale)} ${currency}`
        : formatCurrency(major, currency, locale, value.precision);
    }
    case 'percentage':
      // The API sends 2.35 for 2.35%, not 0.0235, so it is divided before Intl's
      // percent style multiplies it back.
      return formatPercent(value.value / 100, locale, value.precision);
    case 'ratio':
      return `${formatNumber(value.value, locale, value.precision)}×`;
    case 'count':
      return compact
        ? formatCompact(value.value, locale)
        : formatNumber(value.value, locale, value.precision);
  }
}

/** A change, as a signed percentage. Null when there is nothing to compare. */
export function formatChange(value: MetricValue, locale: string): string | null {
  if (value.change_ratio === null) {
    return null;
  }

  const sign = value.change_ratio > 0 ? '+' : '';

  return `${sign}${formatPercent(value.change_ratio, locale, 1)}`;
}
