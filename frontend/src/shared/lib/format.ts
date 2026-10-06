/**
 * Locale-aware formatters for analytics surfaces.
 *
 * Arabic uses Latin digits deliberately: media buyers reconcile these numbers
 * against platform dashboards, which render Latin digits in every locale.
 */
const LOCALE_TAG: Record<string, string> = {
  en: 'en-US',
  ar: 'ar-SA-u-nu-latn',
};

function tagFor(locale: string): string {
  return LOCALE_TAG[locale] ?? 'en-US';
}

export function formatNumber(value: number, locale = 'en', fractionDigits = 0): string {
  return new Intl.NumberFormat(tagFor(locale), {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}

/**
 * Money, to the minor unit.
 *
 * Always two decimals by default, including above a thousand. These figures get
 * reconciled against a platform's own invoice, and a column where 840.25 keeps its
 * cents while 1,234.56 becomes 1,235 is both inconsistent to scan and short of the
 * precision someone is checking. Dense tiles that genuinely cannot afford the
 * width use `formatCompact` instead, which is an explicit choice rather than a
 * silent threshold.
 */
export function formatCurrency(
  value: number,
  currency: string,
  locale = 'en',
  fractionDigits = 2,
): string {
  return new Intl.NumberFormat(tagFor(locale), {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}

/**
 * A rate the API reports out of 100, as a percentage.
 *
 * Separate from `formatPercent` because the two take different units and the mistake is
 * silent: `formatPercent` wants a fraction (0.5 → 50%), and a win rate arriving as `50`
 * would render as 5,000%. Every rate in this product's API — win rate, contact rate,
 * attribution coverage — is 0–100, so this is the one to reach for, and the name says which.
 */
export function formatRate(percent: number, locale = 'en', fractionDigits = 1): string {
  return formatPercent(percent / 100, locale, fractionDigits);
}

/** A fraction as a percentage: 0.1234 → 12.3%. For a rate out of 100 use `formatRate`. */
export function formatPercent(value: number, locale = 'en', fractionDigits = 1): string {
  return new Intl.NumberFormat(tagFor(locale), {
    style: 'percent',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}

/** Compact form for dense KPI tiles: 12.4K, 3.1M. */
export function formatCompact(value: number, locale = 'en'): string {
  return new Intl.NumberFormat(tagFor(locale), {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(value);
}
