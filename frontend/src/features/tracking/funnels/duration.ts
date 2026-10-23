/** A wait as a short phrase in the reader's language: "45 s", "12 min", "3 h", "2 d". Latin digits, as the product shows figures. */
export function formatDuration(seconds: number, locale: string): string {
  const [value, unit] =
    seconds < 90
      ? [seconds, 'second']
      : seconds < 5400
        ? [Math.round(seconds / 60), 'minute']
        : seconds < 129_600
          ? [Math.round(seconds / 3600), 'hour']
          : [Math.round(seconds / 86_400), 'day'];

  return new Intl.NumberFormat(locale.startsWith('ar') ? 'ar-SA-u-nu-latn' : locale, {
    style: 'unit',
    unit: unit ?? 'second',
    unitDisplay: 'narrow',
    maximumFractionDigits: 0,
  }).format(value ?? 0);
}

/** Whole days back from today as the `from`/`to` the API takes (UTC dates, the end inclusive). */
export function periodOfDays(days: number, today: Date = new Date()): { from: string; to: string } {
  const end = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate()));
  const start = new Date(end.getTime() - (days - 1) * 86_400_000);

  return { from: start.toISOString().slice(0, 10), to: end.toISOString().slice(0, 10) };
}
