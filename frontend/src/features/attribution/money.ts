/**
 * Money for an inline sentence.
 *
 * Divides by a hundred exactly once, here, so nothing rounds twice on its way to a reader. The
 * currency is required rather than defaulted: the attribution layer withholds any total it cannot name
 * a currency for, precisely so this function is never asked to guess.
 */
export function formatMinor(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}

/** An inclusive window of the last `days` days, as the API takes it. */
export function windowFor(days: number): { from: string; until: string } {
  const until = new Date();
  const from = new Date(until);

  from.setDate(from.getDate() - (days - 1));

  const iso = (date: Date): string =>
    `${String(date.getFullYear())}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(
      date.getDate(),
    ).padStart(2, '0')}`;

  return { from: iso(from), until: iso(until) };
}
