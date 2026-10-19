/*
 * Money formatting is not here: it is `formatMinor` in `@/shared/lib/format`, which reads
 * the minor-unit scale from the currency rather than assuming a hundred. Four features
 * kept their own copy of it, all four assuming two decimals, which reported a yen a
 * hundredfold over and a dinar a thousandfold.
 */

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
