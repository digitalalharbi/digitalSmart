/** 13.6M SAR rather than 13,580,070.00: a headline figure is read, not reconciled — the exact one is a tap away. */
export function compactMoney(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 100);
}
