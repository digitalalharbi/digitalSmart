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

export function formatCurrency(value: number, currency: string, locale = 'en'): string {
  return new Intl.NumberFormat(tagFor(locale), {
    style: 'currency',
    currency,
    currencyDisplay: 'narrowSymbol',
    maximumFractionDigits: value >= 1000 ? 0 : 2,
  }).format(value);
}

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
