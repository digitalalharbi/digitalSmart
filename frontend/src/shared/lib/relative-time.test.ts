import { describe, expect, it } from 'vitest';
import { ageInDays, formatRelativeTime } from './relative-time';

const NOW = new Date('2026-06-15T12:00:00Z');

function ago(ms: number): string {
  return new Date(NOW.getTime() - ms).toISOString();
}

describe('formatRelativeTime', () => {
  it('rounds down to the largest whole unit', () => {
    expect(formatRelativeTime(ago(90 * 60_000), 'en', NOW)).toBe('1 hour ago');
    expect(formatRelativeTime(ago(25 * 3_600_000), 'en', NOW)).toBe('yesterday');
    expect(formatRelativeTime(ago(40 * 86_400_000), 'en', NOW)).toBe('last month');
  });

  it('says "now" rather than "0 seconds ago" for something just happened', () => {
    expect(formatRelativeTime(ago(5_000), 'en', NOW)).toBe('this minute');
  });

  /*
   * The reason this goes through Intl rather than a string table: Arabic inflects the noun by count, and
   * three days is not "3 يوم". A hand-rolled pluraliser gets this wrong silently.
   */
  it('inflects correctly in Arabic', () => {
    expect(formatRelativeTime(ago(3 * 86_400_000), 'ar', NOW)).toContain('أيام');
  });

  it('handles a timestamp in the future without reading as the past', () => {
    const future = new Date(NOW.getTime() + 2 * 86_400_000).toISOString();
    expect(formatRelativeTime(future, 'en', NOW)).toBe('in 2 days');
  });

  it('returns an empty string for an unparseable timestamp rather than "Invalid Date"', () => {
    expect(formatRelativeTime('not a date', 'en', NOW)).toBe('');
    expect(ageInDays('not a date', NOW)).toBeNull();
  });

  it('measures age in whole days', () => {
    expect(ageInDays(ago(3.9 * 86_400_000), NOW)).toBe(3);
  });
});
