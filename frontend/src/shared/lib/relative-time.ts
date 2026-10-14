const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/**
 * How long ago, in words — "3 days ago", "منذ ٣ أيام".
 *
 * `Intl.RelativeTimeFormat` rather than a hand-rolled table: Arabic has dual and plural forms that an
 * `n === 1 ? 'day' : 'days'` shape cannot express, and getting them wrong is the kind of error a reader
 * notices immediately and a developer never does.
 *
 * Rounded down to the largest whole unit. An alert that has been open for 25 hours reads "1 day ago", which
 * is what somebody triaging it needs; the exact timestamp belongs in a `title`, and callers pass one.
 */
export function formatRelativeTime(iso: string, locale = 'en', now: Date = new Date()): string {
  const then = new Date(iso);

  if (Number.isNaN(then.getTime())) {
    return '';
  }

  const elapsed = now.getTime() - then.getTime();
  const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  /* Signed the way the formatter wants it: the past is negative. */
  const direction = elapsed < 0 ? 1 : -1;
  const distance = Math.abs(elapsed);

  if (distance < MINUTE) {
    return format.format(0, 'minute');
  }

  if (distance < HOUR) {
    return format.format(direction * Math.floor(distance / MINUTE), 'minute');
  }

  if (distance < DAY) {
    return format.format(direction * Math.floor(distance / HOUR), 'hour');
  }

  if (distance < 30 * DAY) {
    return format.format(direction * Math.floor(distance / DAY), 'day');
  }

  if (distance < 365 * DAY) {
    return format.format(direction * Math.floor(distance / (30 * DAY)), 'month');
  }

  return format.format(direction * Math.floor(distance / (365 * DAY)), 'year');
}

/**
 * The age of something, in whole days.
 *
 * Separate from the sentence above because a list that sorts by age needs a number, and parsing
 * "3 days ago" back into one is how a sort comes to disagree with what it displays.
 */
export function ageInDays(iso: string, now: Date = new Date()): number | null {
  const then = new Date(iso);

  return Number.isNaN(then.getTime()) ? null : Math.floor((now.getTime() - then.getTime()) / DAY);
}
