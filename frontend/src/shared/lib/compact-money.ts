/**
 * SAR 13.6M rather than 13,580,070.00: a headline figure is read, not reconciled — the exact one is a tap away.
 *
 * The same compact form in both languages ("SAR 120K", "$42K"). Arabic's own compact words and its currency symbol
 * make a figure that bidirectional text then scrambles — a full stop before the number, a unit after it — and a
 * figure a media buyer reconciles against a platform dashboard has to read the same in either interface.
 */
export function compactMoney(minor: number, currency: string, _locale: string): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    currencyDisplay: currency === 'USD' ? 'narrowSymbol' : 'code',
    notation: 'compact',
    maximumFractionDigits: 1,
  })
    .format(minor / 100)
    .replace(/\u00a0/g, ' ');
}
