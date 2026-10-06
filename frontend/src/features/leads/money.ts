/**
 * Money for an inline sentence.
 *
 * Divides by a hundred exactly once, here, so nothing rounds twice on its way to a reader.
 * A currency is required rather than defaulted: an amount labelled with the wrong currency
 * is worse than an amount with none, and the Lead Center withholds a total it cannot name
 * a currency for precisely so this function is never asked to guess.
 */
export function formatMinor(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}
